---
date: 2019-12-20
layout: post
title: Notes on granting cross account upload access to your S3 bucket
category: posts
tags: [aws, s3, iam]
---

Let's imagine that you are in a situation where you need to allow a third party to upload data to your S3 bucket.

### What not to do


At first blush the solution is pretty simple (as described [here](https://aws.amazon.com/premiumsupport/knowledge-center/s3-cross-account-upload-access/))

Shortcomings:

* the account that owns the IAM Role/User used in the transfer will be the owner of the files when they land in the destination bucket.  You can set “owner-full-control” on the file which allows the recipient account to do most things with the files, but it doesn’t allow the recipient account to share the files with other accounts.

- [Require full control of file](https://aws.amazon.com/premiumsupport/knowledge-center/s3-require-object-ownership/)
- [Take ownership on upload](https://docs.aws.amazon.com/AmazonS3/latest/userguide/about-object-ownership.html)


### What to do

* [Tutorial](https://docs.aws.amazon.com/IAM/latest/UserGuide/tutorial_cross-account-with-roles.html)
* [Details about asking the third party to utilize an External ID for improved safety](https://docs.aws.amazon.com/IAM/latest/UserGuide/id_roles_create_for-user_externalid.html)
