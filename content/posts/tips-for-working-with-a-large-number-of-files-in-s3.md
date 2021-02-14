---
date: 2020-05-30
layout: post
title: Tips for working with a large number of files in S3
category: posts
tags: [python, aws, s3]
aliases: [tips_for_working_with_a_large_number_of_files_in_s3]
---

I would argue that S3 is basically AWS' best service. It's super cheap, it's basically infinitely scalable, and it never goes down (except for [when it does](https://www.theregister.co.uk/2017/03/01/aws_s3_outage/)). Part of its beauty is its simplicity. You give it a file and a key to identify that file, you can have faith that it will store it without issue. You give it a key, you can have faith that it will return the file represented by that key, assuming there is one.

However, when you've enlisted S3 to manage a large number of files (1M+), it can get complicated to do anything beyond doing simple writes and retrievals. Fortunately, there are a number of helpers available to make it manageable to working with this scale of data. This post aims to capture some common workflows that may be of use when working with huge S3 buckets.

## Listing Files

The mere act of listing all of the data within a huge S3 bucket is a challenge. S3's [list-objects API](https://docs.aws.amazon.com/cli/latest/reference/s3api/list-objects.html) returns a max of 1000 items per request, meaning you'll have to work through thousands of pages of API responses to fully list all items within the bucket. To make this simpler, we can utilize [S3's Inventory](https://docs.aws.amazon.com/AmazonS3/latest/dev/storage-inventory.html).

> Amazon S3 inventory provides comma-separated values (CSV), Apache optimized row columnar (ORC) or Apache Parquet (Parquet) output files that list your objects and their corresponding metadata on a daily or weekly basis for an S3 bucket or a shared prefix.

Be aware that it can take up to 48 hours to generate an Inventory Report. From that point forward, reports can be generated on a regular interval.

An inventory report serves as a great first-step when attempting to do any processing on an entire bucket of files. Often, you don't need to retrieve the inventory report manually from S3. Instead, it can be fed into Athena or S3 Batch Operations as described below.

However, when you do need to access the data locally, downloading and reading all of the gzipped CSV files that make up an inventory report can be somewhat tedious. The following script was written to help with this process. Its output can be piped to a local CSV file to create a single output or sent to another function for processing.

<details>
  <summary>Stream S3 Inventory Report Python script</summary>

```py
import json
import csv
import gzip

import boto3

s3 = boto3.resource('s3')


def list_keys(bucket, manifest_key):
    manifest = json.load(s3.Object(bucket, manifest_key).get()['Body'])
    for obj in manifest['files']:
        gzip_obj = s3.Object(bucket_name=bucket, key=obj['key'])
        buffer = gzip.open(gzip_obj.get()["Body"], mode='rt')
        reader = csv.reader(buffer)
        for row in reader:
            yield row


if __name__ == '__main__':
    bucket = 's3-inventory-output-bucket'
    manifest_key = 'path/to/my/inventory/2019-12-15T00-00Z/manifest.json'

    for bucket, key, *rest in list_keys(bucket, manifest_key):
        print(bucket, key, *rest)
```

</details>

## Querying files by S3 Properties

Sometimes you may need a subset of the files within S3, based some metadata property of the object (e.g. the key's extension). While you can use the S3 list-objects API to list files beginning with a particular prefix, you can not filter by suffix. To get around this limitation, we can utilize AWS Athena to query over an S3 Inventory report.

<details>
  <summary>1. Create a table</summary>

_This example assumes that you chose `CSV` as the S3 Inventory Output Format. For information on other formats, review [the docs.](https://docs.aws.amazon.com/athena/latest/ug/supported-format.html)_

```sql
CREATE EXTERNAL TABLE your_table_name(
  `bucket` string,
  key string,
  version_id string,
  is_latest boolean,
  is_delete_marker boolean,
  size bigint,
  last_modified_date timestamp,
  e_tag string,
  storage_class string,
  is_multipart_uploaded boolean,
  replication_status string,
  encryption_status string,
  object_lock_retain_until_date timestamp,
  object_lock_mode string,
  object_lock_legal_hold_status string
  )
  PARTITIONED BY (dt string)
  ROW FORMAT DELIMITED
    FIELDS TERMINATED BY ','
    ESCAPED BY '\\'
    LINES TERMINATED BY '\n'
  STORED AS INPUTFORMAT 'org.apache.hadoop.hive.ql.io.SymlinkTextInputFormat'
  OUTPUTFORMAT  'org.apache.hadoop.hive.ql.io.IgnoreKeyTextOutputFormat'
  LOCATION 's3://destination-prefix/source-bucket/YOUR_CONFIG_ID/hive/';
```

</details>

<details>
  <summary>2. Add inventory reports partitions</summary>

```sql
MSCK REPAIR TABLE your_table_name;
```

</details>

<details>
  <summary>3. Query for S3 keys by their filename, size, storage class, etc</summary>

```sql
SELECT storage_class, count(*) as count
FROM your_table_name
WHERE dt = '2019-12-22-00-00'
GROUP BY storage_class
```

</details>

More information about querying Storage Inventory files with Athena can be [found here](https://docs.aws.amazon.com/AmazonS3/latest/dev/storage-inventory.html#storage-inventory-athena-query).

## Processing Files

Situations may arise where you need to run all (or a large number) of the files within an S3 bucket through some operation. [S3 Batch Operations](https://docs.aws.amazon.com/AmazonS3/latest/user-guide/batch-ops.html) (not to be confused with [AWS Batch](https://aws.amazon.com/batch/)) is built to do the following:

> copy objects, set object tags or access control lists (ACLs), initiate object restores from Amazon S3 Glacier, or invoke an AWS Lambda function to perform custom actions using your objects.

With that last feature, invoking an AWS Lambda function, we can utilize Batch Operations to process a massive number of files without dealing without any of the complexity associated with data-processing infrastructure. Instead, we provide the Batch Operations with a CSV or S3 Inventory Manifest file and a Lambda function to run over each file.

To work with S3 Batch Operations, the lambda function must return a particular response object to describe if the process succeeded, failed, or failed but should be retried.

<details>
  <summary>S3 Batch Operation Boilerplate Python script</summary>

```py
import urllib

import boto3
from botocore.exceptions import ClientError

s3 = boto3.resource("s3")


TMP_FAILURE = "TemporaryFailure"
FAILURE = "PermanentFailure"
SUCCESS = "Succeeded"


def process_object(src_object):
    return "TODO: Populate with processing task..."


def get_task_id(event):
    return event["tasks"][0]["taskId"]


def parse_job_parameters(event):
    # Parse job parameters from Amazon S3 batch operations
    # jobId = event["job"]["id"]
    invocationId = event["invocationId"]
    invocationSchemaVersion = event["invocationSchemaVersion"]
    return dict(
        invocationId=invocationId, invocationSchemaVersion=invocationSchemaVersion
    )


def get_s3_object(event):
    # Parse Amazon S3 Key, Key Version, and Bucket ARN
    s3Key = urllib.parse.unquote(event["tasks"][0]["s3Key"])
    s3VersionId = event["tasks"][0]["s3VersionId"]  # Unused
    s3BucketArn = event["tasks"][0]["s3BucketArn"]
    s3Bucket = s3BucketArn.split(":::")[-1]
    return s3.Object(s3Bucket, s3Key)


def build_result(status: str, msg: str):
    return dict(resultCode=status, resultString=msg)


def handler(event, context):
    task_id = get_task_id(event)
    job_params = parse_job_parameters(event)
    s3_object = get_s3_object(event)

    try:
        output = process_object(s3_object)
        # Mark as succeeded
        result = build_result(SUCCESS, output)
    except ClientError as e:
        # If request timed out, mark as a temp failure
        # and Amazon S3 batch operations will make the task for retry. If
        # any other exceptions are received, mark as permanent failure.
        errorCode = e.response["Error"]["Code"]
        errorMessage = e.response["Error"]["Message"]
        if errorCode == "RequestTimeout":
            result = build_result(
                TMP_FAILURE, "Retry request to Amazon S3 due to timeout."
            )
        else:
            result = build_result(FAILURE, f"{errorCode}: {errorMessage}")
    except Exception as e:
        # Catch all exceptions to permanently fail the task
        result = build_result(FAILURE, f"Exception: {e}")

    return {
        **job_params,
        "treatMissingKeysAs": "PermanentFailure",
        "results": [{**result, "taskId": task_id}],
    }
```

</details>

S3 Batch Operations will then run every key through this Lambda handler, retry temporary failures, and log its results in result files. The result files are conveniently grouped by success/failure status and linked to from a Manifest Result File.

<details>
  <summary>Example Manifest Result File</summary>

```json
{
  "Format": "Report_CSV_20180820",
  "ReportCreationDate": "2019-04-05T17:48:39.725Z",
  "Results": [
    {
      "TaskExecutionStatus": "succeeded",
      "Bucket": "my-job-reports",
      "MD5Checksum": "83b1c4cbe93fc893f54053697e10fd6e",
      "Key": "job-f8fb9d89-a3aa-461d-bddc-ea6a1b131955/results/6217b0fab0de85c408b4be96aeaca9b195a7daa5.csv"
    },
    {
      "TaskExecutionStatus": "failed",
      "Bucket": "my-job-reports",
      "MD5Checksum": "22ee037f3515975f7719699e5c416eaa",
      "Key": "job-f8fb9d89-a3aa-461d-bddc-ea6a1b131955/results/b2ddad417e94331e9f37b44f1faf8c7ed5873f2e.csv"
    }
  ],
  "ReportSchema": "Bucket, Key, VersionId, TaskStatus, ErrorCode, HTTPStatusCode, ResultMessage"
}
```

</details>

More information about the Complete Report format can be [found here](https://docs.aws.amazon.com/AmazonS3/latest/dev/batch-ops-examples-reports.html).

---

At time of writing, S3 Batch Operations cost $0.25 / job + $1 / million S3 objects processed.

Price to process 5 million thumbnails in 2hrs:

- S3 Batch Operations: $0.25 + (5 \* $1) = $5.25
- Lambda: 128MB _ 2000 ms _ 5,000,000 = $21.83
- S3 Get Requests: 5,000,000 / 1000 \* $0.0004 = $2
- S3 Put Requests: 5,000,000 / 1000 \* $0.005 = $25
- TOTAL: $54.08

## Things not discussed in this post

If you are looking for more techniques on querying data stored in S3, consider the following:

- [Using Athena to query the contents of your files stored in S3](https://aws.amazon.com/blogs/big-data/analyzing-data-in-s3-using-amazon-athena/)
- [Using S3 Select to select a subset of a single (very large) file stored in S3](https://aws.amazon.com/blogs/aws/s3-glacier-select/)
