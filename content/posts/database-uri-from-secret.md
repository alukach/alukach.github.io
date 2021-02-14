---
date: 2020-06-15
layout: post
title: How to generate a database URI from an AWS Secret
category: posts
tags: [python, aws, cdk, secretsmanager]
aliases: [database_uri_from_secret]
---

A quick note about how to generate a database URI (or any other derived string) from an AWS SecretsManager [SecretTargetAttachment](https://docs.aws.amazon.com/cdk/api/latest/docs/@aws-cdk_aws-secretsmanager.SecretTargetAttachment.html) (such as what's provided via a RDS DatabaseInstance's [`secret` property](https://docs.aws.amazon.com/cdk/api/latest/docs/@aws-cdk_aws-rds.DatabaseInstance.html#secret-span-class-api-icon-api-icon-experimental-title-this-api-element-is-experimental-it-may-change-without-notice-span)).

```py
db = rds.DatabaseInstance(
    # ...
)
db_val = lambda field: db.secret.secret_value_from_json(field).to_string()
task_definition.add_container(
    environment=dict(
        # ...
        PGRST_DB_URI=f"postgres://{db_val('username')}:{db_val('password')}@{db_val('host')}:{db_val('port')}/",
    ),
    # ...
)
```
