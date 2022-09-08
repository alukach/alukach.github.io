---
date: 2022-09-08
layout: post
title: SSH tunnels in Python
categories: ["snippets"]
tags: [python, ssh, tunnel]
---


A convenience function to assume a IAM Role via [STS](https://docs.aws.amazon.com/STS/latest/APIReference/welcome.html) before running a command.

Add the following to your `~/.zshrc` (or equivalent) file:

```sh
function with-role {
    readonly role_arn=${1:?"The role_arn must be specified."}
    env -S $(
        aws sts assume-role \
        --role-arn ${role_arn} \
        --role-session-name ${USER} \
        | \
        jq -r '.Credentials | "
          AWS_ACCESS_KEY_ID=\(.AccessKeyId)
          AWS_SECRET_ACCESS_KEY=\(.SecretAccessKey)
          AWS_SESSION_TOKEN=\(.SessionToken)
        "'
    ) ${@:2}
}

```

This assumes that you have both the [AWS CLI](https://aws.amazon.com/cli/) and [jq](https://stedolan.github.io/jq/) installed.

Example usage:

```
with-role arn:aws:iam::123456789012:role/someSpecialRole aws s3 ls
```
