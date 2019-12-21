---
layout: post
title: Boilerplate for S3 Batch Operation Lambda
category: posts
tags: [python, aws, s3, batch operation, lambda]
---

[S3 Batch Operation](https://docs.aws.amazon.com/AmazonS3/latest/user-guide/batch-ops.html) provide a simple way to process a number of files stored in an S3 bucket with a Lambda function. However, the Lambda function must return [particular Response Codes](https://docs.aws.amazon.com/AmazonS3/latest/dev/batch-ops-invoke-lambda.html).  Below is an example of a Lambda function written in Python that works with AWS S3 Batch Operations.

{% gist 20b17d4deff4f8b17d2d1cf7490fe1c0 %}
