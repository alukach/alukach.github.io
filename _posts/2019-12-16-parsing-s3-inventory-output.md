---
layout: post
title: Parsing S3 Inventory CSV output in Python
category: posts
tags: [python, s3, inventory, csv]
---

[S3 Inventory](https://docs.aws.amazon.com/AmazonS3/latest/dev/storage-inventory.html) is a great way to access a large number of keys in an S3 Bucket. Its output is easily parsed by [AWS Athena](https://docs.aws.amazon.com/athena/latest/ug//what-is.html), enabling queries across the key names (e.g. find all keys ending with `.png`)

However, sometime you just need to list all of the keys mentioned in the S3 Inventory output (e.g. populating an SQS queue with every keyname mentioned in an inventory output). The following code is an example of doing such task in Python:

{% gist 1a2b8b6366410fb94fa5cee7f72ee304 %}
