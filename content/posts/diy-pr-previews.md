---
date: 2021-11-21
layout: post
title: Roll your own PR preview CI pipeline
categories: ["posts"]
tags: [devops, github, actions, s3]
---

## Goal

We want a CI pipeline that will build and deploy an instance of our frontend application for every PR created in our frontend repo.  Additionally, we want to be able to easily spin up applications with overridden configuration to allow developers to test the frontend against experimental backends.  Finally, we want a reporting mechanism to inform developers when and where these deployed environments are available.

### Other Options

Before you jump into this, consider that there are out-of-the-box solutions to solve this problem mentioned in the [followup](#followup) at the bottom of this page.

## Background: Project Infrastructure

Our production frontend is a React application (using Next.js).  We build this application into static HTML/CSS/JS files and upload them to an S3 bucket. This bucket has been [setup to serve static websites](https://docs.aws.amazon.com/AmazonS3/latest/userguide/WebsiteHosting.html) and is served via HTTPS by CloudFront (see #270).

The frontend accesses multiple backend APIs (e.g. a STAC API, a FastAPI REST API). Deployment of those APIs is outside of the scope of the frontend codebase.

## CI: Cloud Infrastructure

Our goal is to mimic the production frontend deployment to a reasonable degree.

### 1. Setup a bucket

First, we will need an S3 bucket to store our builds.  We created a bucket (`s3://project-frontend-ci`) and configured it to serve static websites.  As a sanity check, we wrote a simple message to a public file that we place at the root of the bucket:

```sh
echo "Project frontend CI builds" | aws s3 cp - s3://project-frontend-ci/index.html --acl public-read --content-type text/html
```

We can verify that everything is configured properly by visiting the bucket's website url: http://project-frontend-ci.s3-website.us-east-1.amazonaws.com/

### 2. Setup SSL via API Gateway

Unfortunately, S3 does not support serving content via SSL (i.e. over HTTPS).  As such, we need to use something like API Gateway or CloudFront to accept traffic over HTTPS and to route it to our bucket's content over HTTP.  For the sake of simplicity, we chose to use API Gateway for this purpose. For our actual staging environment, we utilize CloudFront to more closely mimic the production environment, however for these temporary CI builds we felt that API Gateway was close-enough.

We created an API Gateway [HTTP API](https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api.html) configured to direct all traffic to our S3 bucket's website URL: 
    ![image](https://user-images.githubusercontent.com/897290/141352748-e30c371c-523b-4f31-89bb-618b7f87aa6b.png)

We can now visit our bucket over SSL via the API Gateway endpoint: https://0zy9z5ko27.execute-api.us-east-1.amazonaws.com/


### 3. Setup a URL for the CI builds

The downside of API Gateway or CloudFront is that it produces very unmemorable URLs.  Just to be a bit fancy 💅 , we set up a custom URL on `domain.com`.  We settled on `ci.project-staging.domain.com` to pair nicely with our staging environment (`project-staging.domain.com`).  

<details>

<summary>Setting this up is a bit of a multi-step process. Click here to see the details...</summary>

#### a. Create SSL Certificate

On the AWS account owns the API Gateway HTTP API we just setup, we created an SSL Certificate via AWS Certificate Manager (ACM):

![image](https://user-images.githubusercontent.com/897290/141357476-cafcc308-2215-49d2-b3c5-302f944fd16a.png)

#### b. Verify ownership of domain

ACM requires that you verify that you have control of a domain before it will grant you an SSL certificate.  After creating an SSL certificate, you'll see that it is in "Pending Validation" status.  

![image](https://user-images.githubusercontent.com/897290/141358311-217d51dd-8b13-413c-95f4-88c0c61d1b69.png)

To verify that we control `domain.com`, we add a CNAME record to the `domain.com` hosted zone.  Once this is done, we frantically refresh the ACM status page until it states that our domain has been verified.

#### c. Setup API Gateway custom domain

Back over to API Gateway, we set up a custom domain.

![image](https://user-images.githubusercontent.com/897290/141358750-797889a4-d79b-492f-b9fd-c4f0015a1766.png)

After creating the custom domain, we add an API mapping to our HTTP API.

![image](https://user-images.githubusercontent.com/897290/141359117-c5ea1a3d-6bbf-4efa-bf61-813509358eb2.png)

#### d. Creating a DNS entry for our new URL

We now want to instruct Route53 to direct all traffic sent to our URL (`ci.project-staging.domain.com`) to our new API Gateway custom domain.  To do this, we copy the API Gateway domain name.

![image](https://user-images.githubusercontent.com/897290/141359323-e8eec982-af38-4246-b49a-5099ea1ec5af.png)

We use the copied API Gateway domain name to create a new DNS entry to facilitate this mapping:

![image](https://user-images.githubusercontent.com/897290/141360545-c020accc-e4b3-46b0-ab50-82723b356a2e.png)

</details>

After this, we should be able to see our sanity-check message at https://ci.project-staging.domain.com.

## CI: Workflows

### Building & Deploying

Our goal is to build a version of our frontend application for every pull request and have it available at https://ci.project-staging.domain.com.  Our chosen strategy was to build each PR and to place the build in a path prefixed with the PR number (i.e. PR 208 should be available at https://ci.project-staging.domain.com/208).  To facilitate this, your frontend application must be configured to allow it to be served from non-route paths.  In the case of NextJS, this is done via the [Base Path configuration](https://nextjs.org/docs/api-reference/next.config.js/basepath).

Building and Deploying the application via Github actions is pretty straightforward.

<details>

<summary>Example of a simple build/deploy Github Actions workflow</summary>

```yaml
name: Deploy to CI environment
on:
  pull_request:

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Cancel Previous Runs
        uses: styfle/cancel-workflow-action@0.8.0
        with:
          access_token: ${{ github.token }}

      - name: Checkout
        uses: actions/checkout@v2

      - name: Use Node.js 14
        uses: actions/setup-node@v1
        with:
          node-version: 14

      - name: Cache node modules
        uses: actions/cache@v2
        env:
          cache-name: cache-node-modules
        with:
          path: node_modules
          key: ${{ runner.os }}-build-${{ env.cache-name }}-${{ hashFiles('**/yarn.lock') }}
          restore-keys: |
            ${{ runner.os }}-build-${{ env.cache-name }}-
            ${{ runner.os }}-build-
            ${{ runner.os }}-

      - name: Build and Export
        id: build
        env:
          NEXT_PUBLIC_BASE_URL: https://ci.project-staging.domain.com/${{ github.event.pull_request.number }}
          NEXT_PUBLIC_STAC_API: ${{ 'https://project-staging.domain.com/stac' }}
          NEXT_PUBLIC_ORDERS_API: ${{ 'https://project-staging.domain.com/api' }}
        run: |
          yarn install
          yarn build
          yarn run next export

      - name: Configure AWS credentials from staging account
        uses: aws-actions/configure-aws-credentials@v1
        with:
          aws-access-key-id: ${{ secrets.STAGING_AWS_ACCESS_KEY_ID }}
          aws-secret-access-key: ${{ secrets.STAGING_AWS_SECRET_ACCESS_KEY }}
          aws-region: us-east-1

      - name: Deploy 🚀
        run: |
          aws s3 sync \
            ./out \
            s3://project-frontend-ci/${{ github.event.pull_request.number }} \
            --delete \
            --acl public-read
```

You can see that we pass in our Base URL and external APIs via the `env` at build time and that we have our AWS credentials available as [encrypted secrets](https://docs.github.com/en/actions/security-guides/encrypted-secrets).

Note that, as per the [Github docs](https://docs.github.com/en/actions/learn-github-actions/events-that-trigger-workflows#pull_request), the `pull_request` event only triggers when a PR is opened, updated, or re-opened:

> By default, a workflow only runs when a `pull_request`'s activity type is `opened`, `synchronize`, or `reopened`.

</details>

### Adding a comment on our PR to notify others of the build

Once the CI has built and deployed a new instance of our frontend, we want to notify others (e.g. those reviewing PRs) where they can view the build.  To do this, we add the following steps to our Github Actions workflow to take place after our build:

<details>

<summary>Example of jobs to add comments to a PR</summary>

```yaml
jobs:
  build-and-deploy:
    steps:
      # ...

      - name: Get current time
        uses: gerred/actions/current-time@master
        id: current-time

      - name: Find Comment
        uses: peter-evans/find-comment@v1
        id: find-comment
        with:
          issue-number: ${{ github.event.pull_request.number }}
          comment-author: "github-actions[bot]"
          body-includes: Latest commit deployed to

      - name: Create or update comment
        uses: peter-evans/create-or-update-comment@v1
        with:
          comment-id: ${{ steps.find-comment.outputs.comment-id }}
          issue-number: ${{ github.event.pull_request.number }}
          body: |
            🚀 Latest commit deployed to https://ci.project-staging.domain.com/${{ github.event.pull_request.number }}
            * Date: `${{ steps.current-time.outputs.time }}`
            * Commit: ${{ github.sha }} (Merging ${{ github.event.pull_request.head.sha }} into ${{ github.event.pull_request.base.sha }})
          edit-mode: replace
```

</details>

These steps add a new comment to PRs, looking something like this:

![image](https://user-images.githubusercontent.com/897290/141362960-87186629-d0f7-4501-a8c3-273bb923c7bc.png)

For later commits to the PR, the original comment will be replaced rather than creating another comment. This helps us avoid littering user's notifications and keeps a clean comment thread.

### Allow users to customize configuration

As previously mentioned, the frontend connects to multiple backing APIs.  By default, the CI builds point to our staging APIs.  However, it's a realistic scenario that a developer would want their custom environment to point to a different API release (e.g. a developer is working on frontend changes in tandem with changes being made to the backend API).  To support this, we want to allow developers to manually override certain configurations.  To do this, we added the `workflow_dispatch` trigger to our workflow, allowing for [manual workflow runs](https://docs.github.com/en/actions/managing-workflow-runs/manually-running-a-workflow#running-a-workflow-using-the-rest-api).  We also add `inputs` for each configuration we want to allow a developer to specify.

<details>

<summary>Example of adding <code>workflow_dispatch</code> event with <code>inputs</code> to your workflow</summary>

```yaml
name: Deploy to CI environment
on:
  pull_request:
  workflow_dispatch:
    inputs:
      stac-api-url:
        description: Override STAC API URL
        default: https://project-staging.domain.com/stac
      orders-api-url:
        description: Override Orders API URL
        default: https://project-staging.domain.com/api
      deployment-id:
        description: Unique identifier for build (used to construct path for upload)
        required: true

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest

    steps:

      # ...

      - name: Build and Export
        id: build
        env:
          NEXT_PUBLIC_BASE_URL: https://ci.project-staging.domain.com/${{ github.event.inputs.deployment-id || github.event.pull_request.number }}
          NEXT_PUBLIC_STAC_API: ${{ github.event.inputs.stac-api-url || 'https://project-staging.domain.com/stac' }}
          NEXT_PUBLIC_ORDERS_API: ${{ github.event.inputs.orders-api-url || 'https://project-staging.domain.com/api' }}
          NEXT_PUBLIC_MB_TOKEN: pk.eyJ1IjoiZGV2c2VlZCIsImEiOiJjazB6YXU2bDUwMWNkM2VvNGNpMnFhOXMxIn0.c30a2TQIfCDF3GlqMdSQ_g
          NEXT_PUBLIC_GA_ID: GTM-WNP7MLF
        run: |
          yarn install
          yarn build
          yarn run next export

      - name: Get current time
        uses: gerred/actions/current-time@master
        if: ${{ github.event.pull_request.number }}
        # ...

      - name: Find Comment
        uses: peter-evans/find-comment@v1
        if: ${{ github.event.pull_request.number }}
        # ...

      - name: Create or update comment
        uses: peter-evans/create-or-update-comment@v1
        if: ${{ github.event.pull_request.number }}
        # ...
```

</details>

You'll note that any place where we originally specified our API configuration or had a dependency on a PR number, we now first try to retrieve the value from `github.event.inputs` (only available during manual `workflow_dispatch` events) and otherwise fall back to values used during standard PR builds.  This can be achieved by utilizing the [OR operator](https://docs.github.com/en/actions/learn-github-actions/expressions#operators) as so: `${{ github.event.inputs.deployment-id || github.event.pull_request.number }}`.

Additionally, we will only want to comment on a PR during PR builds, so we avoid running the comment steps by adding an `if: ${{ github.event.pull_request.number }}` clause to each step that we want to skip.

### Cleanup

After each PR is merged, we want to clean up the past build to avoid unnecessary storage in our CI bucket.  This can be achieved with another workflow that cleans up the build whenever a pull request is closed.

<details>

<summary>Example of a workflow to destroy CI builds</summary>

```yaml
name: Destroy PR Preview

on:
  pull_request:
    types: [closed]
  workflow_dispatch:
    inputs:
      deployment-id:
        description: Unique identifier of CI build to be deleted
        required: true

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest

    steps:

      # ...

      - name: Destroy 💣
        run: |
          aws s3 rm --recursive s3://project-frontend-ci/${{ github.event.inputs.deployment-id || github.event.pull_request.number }}/

      - name: Get current time
        uses: gerred/actions/current-time@master
        if: ${{ github.event.pull_request.number }}
        id: current-time

      - name: Find Comment
        uses: peter-evans/find-comment@v1
        if: ${{ github.event.pull_request.number }}
        id: find-comment
        with:
          issue-number: ${{ github.event.pull_request.number }}
          comment-author: "github-actions[bot]"
          body-includes: Latest commit deployed to

      - name: Create or update comment
        uses: peter-evans/create-or-update-comment@v1
        if: ${{ github.event.pull_request.number }}
        with:
          comment-id: ${{ steps.find-comment.outputs.comment-id }}
          issue-number: ${{ github.event.pull_request.number }}
          body: |
            ---
            🧹 Deleted build at https://ci.project-staging.domain.com/${{ github.event.inputs.deployment-id || github.event.pull_request.number }} 
            
            * Date: `${{ steps.current-time.outputs.time }}`
          edit-mode: append
```
</details>

Our pull request message is then appended with information to let others know that the build environment is no longer available.

![image](https://user-images.githubusercontent.com/897290/141370017-cf8b9fd2-ae20-46cd-9a0c-74083cb36a11.png)



### Putting it all together

To achieve our goals of deployment, notification, customization, and cleanup, we have settled on these two Github workflows:

<details>

<summary><code>.github/workflows/deploy-pr-preview.yml</code></summary>


```yaml
name: Deploy to CI environment
on:
  pull_request:
  workflow_dispatch:
    inputs:
      stac-api-url:
        description: Override STAC API URL
        default: https://project-staging.domain.com/stac
      orders-api-url:
        description: Override Orders API URL
        default: https://project-staging.domain.com/api
      deployment-id:
        description: Unique identifier for build (used to construct path for upload)
        required: true

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Cancel Previous Runs
        uses: styfle/cancel-workflow-action@0.8.0
        with:
          access_token: ${{ github.token }}

      - name: Checkout
        uses: actions/checkout@v2

      - name: Use Node.js 14
        uses: actions/setup-node@v1
        with:
          node-version: 14

      - name: Cache node modules
        uses: actions/cache@v2
        env:
          cache-name: cache-node-modules
        with:
          path: node_modules
          key: ${{ runner.os }}-build-${{ env.cache-name }}-${{ hashFiles('**/yarn.lock') }}
          restore-keys: |
            ${{ runner.os }}-build-${{ env.cache-name }}-
            ${{ runner.os }}-build-
            ${{ runner.os }}-

      - name: Build and Export
        id: build
        env:
          NEXT_PUBLIC_BASE_URL: https://ci.project-staging.domain.com/${{ github.event.inputs.deployment-id || github.event.pull_request.number }}
          NEXT_PUBLIC_STAC_API: ${{ github.event.inputs.stac-api-url || 'https://project-staging.domain.com/stac' }}
          NEXT_PUBLIC_ORDERS_API: ${{ github.event.inputs.orders-api-url || 'https://project-staging.domain.com/api' }}
          NEXT_PUBLIC_MB_TOKEN: pk.eyJ1IjoiZGV2c2VlZCIsImEiOiJjazB6YXU2bDUwMWNkM2VvNGNpMnFhOXMxIn0.c30a2TQIfCDF3GlqMdSQ_g
          NEXT_PUBLIC_GA_ID: GTM-WNP7MLF
        run: |
          yarn install
          yarn build
          yarn run next export

      - name: Configure AWS credentials from staging account
        uses: aws-actions/configure-aws-credentials@v1
        with:
          aws-access-key-id: ${{ secrets.STAGING_AWS_ACCESS_KEY_ID }}
          aws-secret-access-key: ${{ secrets.STAGING_AWS_SECRET_ACCESS_KEY }}
          aws-region: us-east-1

      - name: Deploy 🚀
        run: |
          aws s3 sync \
            ./out \
            s3://project-frontend-ci/${{ github.event.inputs.deployment-id || github.event.pull_request.number }} \
            --delete \
            --acl public-read

      - name: Get current time
        uses: gerred/actions/current-time@master
        if: ${{ github.event.pull_request.number }}
        id: current-time

      - name: Find Comment
        uses: peter-evans/find-comment@v1
        if: ${{ github.event.pull_request.number }}
        id: find-comment
        with:
          issue-number: ${{ github.event.pull_request.number }}
          comment-author: "github-actions[bot]"
          body-includes: Latest commit deployed to

      - name: Create or update comment
        uses: peter-evans/create-or-update-comment@v1
        if: ${{ github.event.pull_request.number }}
        with:
          comment-id: ${{ steps.find-comment.outputs.comment-id }}
          issue-number: ${{ github.event.pull_request.number }}
          body: |
            🚀 Latest commit deployed to https://ci.project-staging.domain.com/${{ github.event.inputs.deployment-id || github.event.pull_request.number }}

            * Date: `${{ steps.current-time.outputs.time }}`
            * Commit: ${{ github.sha }} (merging ${{ github.event.pull_request.head.sha }} into ${{ github.event.pull_request.base.sha }})

          edit-mode: replace
```
</details>


<details>

<summary><code>.github/workflows/destroy-pr-preview.yml</code></summary>

```yaml
name: Destroy PR Preview

on:
  pull_request:
    types: [closed]
  workflow_dispatch:
    inputs:
      deployment-id:
        description: Unique identifier of CI build to be deleted
        required: true

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Cancel Previous Runs
        uses: styfle/cancel-workflow-action@0.8.0
        with:
          access_token: ${{ github.token }}

      - name: Configure AWS credentials from staging account
        uses: aws-actions/configure-aws-credentials@v1
        with:
          aws-access-key-id: ${{ secrets.STAGING_AWS_ACCESS_KEY_ID }}
          aws-secret-access-key: ${{ secrets.STAGING_AWS_SECRET_ACCESS_KEY }}
          aws-region: us-east-1

      - name: Destroy 💣
        run: |
          aws s3 rm --recursive s3://project-frontend-ci/${{ github.event.inputs.deployment-id || github.event.pull_request.number }}/

      - name: Get current time
        uses: gerred/actions/current-time@master
        if: ${{ github.event.pull_request.number }}
        id: current-time

      - name: Find Comment
        uses: peter-evans/find-comment@v1
        if: ${{ github.event.pull_request.number }}
        id: find-comment
        with:
          issue-number: ${{ github.event.pull_request.number }}
          comment-author: "github-actions[bot]"
          body-includes: Latest commit deployed to

      - name: Create or update comment
        uses: peter-evans/create-or-update-comment@v1
        if: ${{ github.event.pull_request.number }}
        with:
          comment-id: ${{ steps.find-comment.outputs.comment-id }}
          issue-number: ${{ github.event.pull_request.number }}
          body: |
            ---
            🧹 Deleted build at https://ci.project-staging.domain.com/${{ github.event.inputs.deployment-id || github.event.pull_request.number }} 
            
            * Date: `${{ steps.current-time.outputs.time }}`
          edit-mode: append
```

</details>

---

This system is very new for us and required some additional changes to other services (i.e. updating CORS rules on our APIs to allow us to use this new URL), but so far it seems to be operating as expected.  Hoping that this can help others who are looking to build out better CI preview environments for their applications.

---

### Followup

> this is super cool but was there a reason we couldn’t use netlify or another third party service for this?

This is a fair question. We opted to roll our own solution being that the general idea (uploading builds to S3) was something that we were already doing for deployments to our Production and Staging environments. However, if you're starting a new project, you may be interested in achieving per-PR deployments with a third party tool. It appears that most common hosting solutions offer something for this:

* Netlify offers [Deploy Previews](https://docs.netlify.com/site-deploys/deploy-previews/)
* Vercel offers [Preview URLs](https://vercel.com/docs/concepts/deployments/environments#preview) via its [Github Integration](https://vercel.com/docs/concepts/git/vercel-for-github)
* AWS Amplify offers [Web Previews](https://docs.aws.amazon.com/amplify/latest/userguide/pr-previews.html)
* Surge.sh can be configured to preview URLs via the [surge-preview](https://github.com/afc163/surge-preview) Github Action
