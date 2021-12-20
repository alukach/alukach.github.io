---
date: 2021-12-20
layout: post
title: Security-conscious cloud deployments from Github Actions via OpenID Connect
categories: ["posts"]
tags: [aws, devops, github, actions]
---

## Goals

This ticket is focused on how we can securely deploy to a major cloud provider environment (e.g. AWS, Azure, GCP) from within our Github Actions workflows.

### Why is this challenging?

A naive solution to this problem is to generate some cloud provider credentials (e.g. AWS Access Keys) and to store them as a Github Secret. Our Github Actions can then utilize these credentials in its workflows. However, this technique contains a number of concerns:

1. It is reliant on long-standing credentials being stored in Github Actions. Some environments are unable to generate such long-standing credentials without serious admin intervention (e.g. environments using CloudTamer/Kion).
1. It grants any user with write-access to the repo with full use of the possibly wide-scoped credentials. Currently, within Github there are not a sufficient ways to limit who or what can be done with the credentials. For example, any user with write-access to the repo could create a workflow action that references the production credentials and uses them to teardown the production environment. This is due to the combination of two factors:

   1. The instructions run during the build are entirely specified within the Github repo. This means that anyone can alter them as they see fit.
   1. The cloud providers lacks information about the context of a build (e.g. git branch or github user), and is therefore unable to apply or enforce any sort of restrictions regarding what a build can do.

   [Github Environments](https://docs.github.com/en/actions/deployment/targeting-different-environments/using-environments-for-deployment) solves some of these problems, however at time of writing it is only available on public repositories or private Github Enterprise account repositories, making it an unvialable solution for many of our partners.


## Solution: OpenID Connect

On Nov 23, 2021 Github Actions announced the general availability of support for OpenID Connect (OIDC). For an in-depth understanding of this, I recommend reviewing the following links:

- Announcement: [Secure deployments with OpenID Connect & GitHub Actions now generally available](https://github.blog/2021-11-23-secure-deployments-openid-connect-github-actions-generally-available/)
- Docs: [Security hardening your deployments [with OpenID Connect]](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments)

### High level summary

With OIDC, you can register Github as an Identity Provider within your cloud platform of choice. When your Github Action workflows run, they can be setup to request short-lived credentials from your cloud provider. When the cloud provider grants the access token, it will be associated with a particular IAM Role. That IAM Role should be set up with the permissions necessary for deploying your application.

- No need to store credentials. Github Actions workflows will request a short-lived access token at runtime.
- When a short-lived access token is requested, Github Actions sends an OIDC token with claims describing the context of the workflow ([link](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/about-security-hardening-with-openid-connect#understanding-the-oidc-token)). These claims can be interogated by the cloud provider and used to determine whether or not a token should be granted. This allows us to hardcode security requirements (e.g. limiting particular IAM Roles to specific Github branches, only allowing executions triggered by specified github usernames) in the cloud provider, providing guard-rails to limit what can be done by any particular user with write-access to a Github repository.

### Example with AWS

#### Setup AWS

Setting up OIDC with AWS is described in depth [here](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services), however the following is a quick summary:

1. Add Github as an Identity provider ([docs](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services#adding-the-identity-provider-to-aws)).

   <details>
   <summary>Console Screenshot</summary>

   <img width="885" alt="Screen Shot 2021-12-20 at 9 53 21 AM" src="https://user-images.githubusercontent.com/897290/146828985-c767dfed-d34c-446b-8597-cb4b15fd922c.png">
   </details>

1. Create an IAM Policy for deployment executions. See [recommendations](#recommendations) for tips on how to craft this policy. Below is an example policy for frontend static website deployments:

   <details>
   <summary>Example policy</summary>

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Sid": "SyncS3Bucket",
         "Effect": "Allow",
         "Action": [
           "s3:ListBucket",
           "s3:GetObject",
           "s3:PutObject",
           "s3:PutObjectAcl",
           "s3:DeleteObject"
         ],
         "Resource": [
           "arn:aws:s3:::my-staging-bucket",
           "arn:aws:s3:::my-staging-bucket/*"
         ]
       },
       {
         "Sid": "InvalidateCloudfrontDistribution",
         "Effect": "Allow",
         "Action": ["cloudfront:CreateInvalidation"],
         "Resource": "*"
       }
     ]
   }
   ```

1. Create role for deployment executions.

   <details>
   <summary>Console Screenshot</summary>
   <img width="982" alt="Screen Shot 2021-12-20 at 12 37 18 PM" src="https://user-images.githubusercontent.com/897290/146829615-0359eb95-7859-4433-ad21-fc1b5b17f47c.png">
   </details>

   Attach your revelant policies. Optionally, specify the role's permission boundaries.

   <details>
   <summary>Console Screenshot</summary>

   ![image](https://user-images.githubusercontent.com/897290/146830755-0ddf31ad-7249-4032-8a7e-8278d4319597.png)
   </details>

   For this example, we'll be naming our role `Frontend-Staging-Deployment-Role`

   <details>
   <summary>Console Screenshot</summary>

   ![image](https://user-images.githubusercontent.com/897290/146831434-b69cb9d2-2776-4909-a237-508d9dbf0f8b.png)
   </details>

1. By default, the IAM Role created for our OIDC Web Identity contains a condition where the `aud` claim in our token should match `sts.amazonaws.com`. However, by default the `aud` will be the URL of the repository owner. As such, we need to customize our trust relationship to encorce custom conditions.

   <details>
   <summary>Console Screenshot</summary>

   ![image](https://user-images.githubusercontent.com/897290/146832709-cb125143-9a7b-48c2-9532-71c87191f410.png)
   </details>

   In the following example, we configure the trust relationship to enforce that this role can only be used on builds on the `my-org/my-repo` repository's `staging` branch:

   <details>
   <summary>Example conditions</summary>

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Principal": {
           "Federated": "arn:aws:iam::123456789000:oidc-provider/token.actions.githubusercontent.com"
         },
         "Action": "sts:AssumeRoleWithWebIdentity",
         "Condition": {
           "StringEquals": {
             "token.actions.githubusercontent.com:sub": "repo:my-org/my-repo:ref:refs/heads/staging"
           }
         }
       }
     ]
   }
   ```

   </details>

#### Setup Workflow

Workflows utilizing OIDC need a few particular elements.

1. We need to customize the [permissions of our `GITHUB_TOKEN`](https://docs.github.com/en/actions/security-guides/automatic-token-authentication#permissions-for-the-github_token) via the `permissions` block. The workflow will need to be able to write an `id-token` along with the default permissions of reading the `contents` of the repository:

    <details>
    <summary>Permissions block</summary>

   ```yaml
   permissions:
     id-token: write
     contents: read
   ```

    </details>

1. Add tooling to request the an access token from AWS. For this, the AWS' offical ["Configure AWS Credentials" Action](https://github.com/marketplace/actions/configure-aws-credentials-action-for-github-actions) works well. To use this, you must provide the ARN of the role that you would like to assume in your execution:

   <details>
   <summary>AWS Configuration step</summary>

   ```yaml
   - name: Configure AWS credentials
     uses: aws-actions/configure-aws-credentials@v1
     with:
       role-to-assume: arn:aws:iam::123456789000:role/Frontend-Staging-Deployment-Role
       aws-region: us-west-2
   ```

   </details>

##### Full example

<details>

<summary>A complete example workflow</summary>

```yaml
name: Deploy Staging Frontend

on:
  push:
    branches:
      - staging

permissions:
  id-token: write
  contents: read

jobs:
  build:
    runs-on: ubuntu-latest

    steps:
      - name: Setup Node.js
        uses: actions/setup-node@v2
        with:
          node-version: 12

      - name: Check out repository code
        uses: actions/checkout@v2

      - name: Install dependencies
        run: npm install

      - name: Build code
        run: npm run build

      - name: Configure AWS credentials
        uses: aws-actions/configure-aws-credentials@v1
        with:
          role-to-assume: arn:aws:iam::123456789000:role/Frontend-Staging-Deployment-Role
          aws-region: us-west-2

      - name: Sync with S3 bucket
        env:
          BUCKET: my-staging-bucket
        run: |
          aws s3 sync \
            ./build "s3://${BUCKET}" \
            --acl public-read \
            --follow-symlinks \
            --delete

      - name: Invalidate CloudFront
        env:
          DISTRIBUTION: EDFDVBD6EXAMPLE
        run: |
          aws cloudfront create-invalidation \
            --distribution-id $DISTRIBUTION \
            --paths "/*"
```

In the above example, we have hardcoded the Role ARN, S3 Bucket, and Cloudfront Distribution ID in the workflow file. However, you may prefer to store these values as Github Secrets. This allows the values to be changed without a code change and additionally helps avoid data-leak. An example:

```yaml
- name: Configure AWS credentials
  uses: aws-actions/configure-aws-credentials@v1
  with:
    role-to-assume: ${{ secrets.STAGING_CD_ROLE_ARN }}
    aws-region: us-west-2

- name: Sync with S3 bucket
  env:
    BUCKET: ${{ secrets.STAGING_BUCKET_NAME }}
  run: |
    aws s3 sync \
      ./build "s3://${BUCKET}" \
      --acl public-read \
      --follow-symlinks \
      --delete

- name: Invalidate CloudFront
  env:
    DISTRIBUTION: ${{ secrets.STAGING_DISTRIBUTION_ID }}
  run: |
    aws cloudfront create-invalidation \
      --distribution-id $DISTRIBUTION \
      --paths "/*"
```

</details>

## Recommendations

- Each IAM role should relate to a single deployment. For example, you may have a `Service-X-Frontend-Staging-Deployment` role and a `Service-X-Frontend-Production-Deployment` role, each referencing specific IAM policies that specify the minimal permissions needed to deploy to its respective environment. Each role should specify which repositories and branches can use the role.
- Configuring the IAM role's trust relationship is key to enforcing logic around deployment permissions. Understanding the [`Condition` block](https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_policies_elements_condition.html) and the [Github OIDC token](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/about-security-hardening-with-openid-connect#understanding-the-oidc-token) is paramount.

