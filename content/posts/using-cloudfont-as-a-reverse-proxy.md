---
date: 2020-10-02
layout: post
title: Using CloudFront as a Reverse Proxy
categories: ["posts"]
tags: [python, aws, cdk, cloudfront]
aliases: [using_cloudfont_as_a_reverse_proxy]
---

_Alternate title: How to be master of your domain._

The basic idea of this post is to demonstrate how CloudFront can be utilized as a serverless [reverse-proxy](https://www.cloudflare.com/learning/cdn/glossary/reverse-proxy/), allowing you to host all of your application's content and services from a single domain. This minimizes a project's [TLD](https://en.wikipedia.org/wiki/Top-level_domain) footprint while providing project organization and performance along the way.

## Why

Within large organizations, bureaucracy can make it a challenge to obtain a subdomain for a project. This means that utilizing multiple service-specific subdomains (e.g. `api.my-project.big-institution.gov` or `thumbnails.my-project.big-institution.gov`) is an arduous process. To avoid this in a recent project, we settled on adopting a pattern where we use CloudFront to proxy all of our domain's incoming requests to their appropriate service.

## How it works

CloudFront has the ability to support multiple origin configurations (i.e. multiple sources of content). We can utilize the [Path Pattern](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/distribution-web-values-specify.html#DownloadDistValuesPathPattern) setting to direct web requests by URL path to their appropriate service. CloudFront behaves like a typical router libraries, wherein it routes traffic to the first path with a pattern matching the incoming request and routes requests that don't match route patterns to a default route. For example, our current infrastructure looks like this:

```
my-project.big-institution.gov/
├── api/*         <- Application Load Balancer (ALB) that distributes traffic to order
│                    management API service running on Elastic Container Service (ECS).
│
├── stac/*        <- ALB that distributes traffic to STAC API service running on ECS.
│
├── storage/*     <- Private S3 bucket storing private data. Only URLs that have been
│                    signed with our CloudFront keypair will be successful.
│
├── thumbnails/*  <- Public S3 bucket storing thumbnail imagery.
│
└── *             <- Public S3 website bucket storing our single page application frontend.
```

### Single Page Applications

An S3 bucket configured for [website hosting](https://docs.aws.amazon.com/AmazonS3/latest/dev/WebsiteHosting.html) acts as the origin for our default route. If an incoming request's path does not match routes specified elsewhere within the CloudFront distribution, it is routed to the single page application. To configure the single page application to handle any requests provided (i.e. not just requests sent to paths of existing files within the bucket, such as `index.html` or `app.js`), the bucket should be configured with a [custom error page](https://docs.aws.amazon.com/AmazonS3/latest/dev/CustomErrorDocSupport.html) in response to `404` errors, returning the applications HTML entrypoint (`index.html`).

#### Requirements

To enable the usage of a custom error page, the S3 bucket's website endpoint (i.e. `<bucket-name>.s3-website-<region>.amazonaws.com`, not `<bucket-name>.s3.<region>.amazonaws.com`) must be configured as a custom origin for the distribution. Additionally, the bucket must be configured for public access. More information: [Using Amazon S3 Buckets Configured as Website Endpoints for Your Origin](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/DownloadDistS3AndCustomOrigins.html#concept_S3Origin_website). Being that the S3 website endpoint does not support SSL, the custom origin's [Protocol Policy](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/distribution-web-values-specify.html#DownloadDistValuesOriginProtocolPolicy) should be set to HTTP Only.

> My bucket is private. Can CloudFront serve a website from this bucket?

If your bucket is private, the website endpoint will not work ([source](https://aws.amazon.com/premiumsupport/knowledge-center/s3-cloudfront-website-access/)). You could configure CloudFront to send traffic to the buckets REST API endpoint, however this will prevent you from being able to utilize S3's custom error document feature which may be essential for hosting single page applications on S3.  Tools like [Next.js](https://nextjs.org/) and [Gatsby.js] support rendering HTML documents for all routes, which can avoid the need for custom error pages; however care must be given to ensure that any dynamic portion of the page's routes (e.g. `/docs/3`, where `3` is the ID of a record to be fetched from an API) must be specified as either a query parameter (e.g. `/docs?3`) or a hash (e.g. `/docs#3).

> CloudFront itself has support for custom error pages. Why can't I use that to enable hosting private S3 buckets as websites?

While it is true that CloudFront can route error responses to custom pages (e.g. sending all `404` responses the contents of `s3://my-website-bucket/index.html`), these custom error pages apply to the entirety of your CloudFront distribution. This is likely undesirable for any API services hosted by your CloudFront distribution. For example, if a user accesses a RESTful API at `http://my-website.com/api/notes/12345` and the API server responds with a `404` of `{"details": "Record not found"}`, the response body will be re-written to contain the contents of `s3://my-website-bucket/index.html`. _At time of writing, I am unaware of any capability of applying custom error pages to only certain content-types. A feature such as this might make distribution-wide custom error pages a viable solution._

### APIs

APIs are served as custom origins, with their [Domain Name](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/distribution-web-values-specify.html#DownloadDistValuesDomainName) settings pointing to their an ALB's DNS name.

> Does this work with APIs run with Lambda or EC2?

Assuming that the service has a DNS name, it can be set up as an origin for CloudFront. This means that for an endpoint handled by a Lambda function, you would need to have it served behind an API Gateway or an ALB.

#### Recommended configuration

- [Disable caching](https://aws.amazon.com/premiumsupport/knowledge-center/prevent-cloudfront-from-caching-files/) by setting the default, minimum, and maximum TTL to `0` seconds.
- Set [AllowedMethods](https://docs.aws.amazon.com/cloudfront/latest/APIReference/API_AllowedMethods.html) to forward all requests (i.e. `GET`, `HEAD`, `OPTIONS`, `PUT`, `PATCH`, `POST`, and `DELETE`).
- Set [ForwardedValues](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/aws-properties-cloudfront-distribution-forwardedvalues.html) so that querystring and the following headers are fowarded: `referer`, `authorization`, `origin`, `accept`, `host`
- [Origin Protocol Policy](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/distribution-web-values-specify.html#DownloadDistValuesOriginProtocolPolicy) of HTTP Only.

### Data from S3 Buckets

Data from a standard S3 bucket can be configured by pointing to the bucket's REST endpoint (e.g. `<bucket-name>.s3.<region>.amazonaws.com`). More information: [Using Amazon S3 Buckets for Your Origin](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/DownloadDistS3AndCustomOrigins.html).

This can be a public bucket, in which case would benefit from the CDN and caching provided by CloudFront.

When using a private bucket, CloudFront additionally can serve as a "trusted signer" to enable an application with access to the CloudFront security keys to create signed URLs/cookies to grant temporary access to particular private content. In order for CloudFront to access content within a private bucket, its Origin Access Identity must be given read privileges within the bucket's policy. More information: [Restricting Access to Amazon S3 Content by Using an Origin Access Identity](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-restricting-access-to-s3.html)

## Caveats

The most substantial issue with this technique is the fact that CloudFront does not have the capability to remove portions of a path from a request's URL. For example, if an API is configured as an origin at `https://d1234abcde.cloudfront.net/api`, it should be configured to respond to URLs starting with `/api`. This is often a non-issue, as many server frameworks have builtin support to support being hosted at a non-root path.

<details>
  <summary>Configuring FastAPI to be served under a non-root path</summary>

```py
from fastapi import FastAPI, APIRouter

API_BASE_PATH = '/api'

app = FastAPI(
    title="Example API",
    docs_url=API_BASE_PATH,
    swagger_ui_oauth2_redirect_url=f"{API_BASE_PATH}/oauth2-redirect",
    openapi_url=f"{API_BASE_PATH}/openapi.json",
)
api_router = APIRouter()
app.include_router(router, prefix=API_BASE_PATH)
```

</details>

Furthermore, if you have an S3 bucket serving content from `https://d1234abcde.cloudfront.net/bucket`, only keys with a prefix of `bucket/` will be available to that origin. In the event that keys are not prefixed with a path matching the origins configured path pattern, there are two options:

1. Move all of the files, likely utilizing something like S3 Batch (see #253 for more details)
2. Use a Lambda@Edge function to rewrite the path of any incoming request for a non-cached resource to conform to the key structure of the S3 bucket's objects.

## Summary

After learning this technique, it feels kind of obvious. I'm honestly not sure if this is AWS 101 level technique or something that is rarely done; however I never knew of it before this project and therefore felt it was worth sharing.

A quick summary of some of the advantages that come with using CloudFront for all application endpoints:

- It feels generally tidier to have all your endpoints placed behind a single domain. No more dealing with ugly ALB, API Gateway, or S3 URLs. This additionally pays off when you are dealing with multiple stages (e.g. `prod` and `dev`) of the same service 🧹.
- SSL is managed and terminated at CloudFront. Everything after that is port 80 non-SSL traffic, simplifying the management of certificates 🔒.
- All non-SSL traffic can be set to auto-redirect to SSL endpoints ↩️.
- Out of the box, AWS Shield Standard is applied to CloudFront to provide protection against DDoS attacks 🏰.
- Static content is regionally cached and served from [Edge Locations](https://aws.amazon.com/cloudfront/features/#Amazon_CloudFront_Infrastructure) closer to the viewer 🌏.
- Dynamic content is also served from Edge Locations, which connect to the origin server via AWS' global private network. This is faster than connecting to an origin server over the public internet 🚀.
- Externally, all data is served from the same domain origin. Goodbye CORS errors 👋!
- Data egress costs are lower through CloudFront than other services. This can be ensured by only selecting [Price Class 100](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/PriceClass.html), other price classes can be chosen if enabling a global CDN is worth the higher egress costs 💴.

## Example

<details>
  <summary>An example of a reverse-proxy CloudFront Distribution written with CDK in Python</summary>

```py
from aws_cdk import (
    aws_s3 as s3,
    aws_certificatemanager as certmgr,
    aws_iam as iam,
    aws_cloudfront as cf,
    aws_elasticloadbalancingv2 as elbv2,
    core,
)


class CloudfrontDistribution(core.Construct):
    def __init__(
        self,
        scope: core.Construct,
        id: str,
        api_lb: elbv2.ApplicationLoadBalancer,
        assets_bucket: s3.Bucket,
        website_bucket: s3.Bucket,
        domain_name: str = None,
        using_gcc_acct: bool = False,
        **kwargs,
    ) -> None:
        super().__init__(scope, id, **kwargs)

        oai = cf.OriginAccessIdentity(
            self, "Identity", comment="Allow CloudFront to access S3 Bucket",
        )
        if not using_gcc_acct:
            self.grant_oai_read(oai, assets_bucket)

        certificate = (
            certmgr.Certificate(self, "Certificate", domain_name=domain_name)
            if domain_name
            else None
        )

        self.distribution = cf.CloudFrontWebDistribution(
            self,
            core.Stack.of(self).stack_name,
            alias_configuration=(
                cf.AliasConfiguration(
                    acm_cert_ref=certificate.certificate_arn, names=[domain_name]
                )
                if certificate
                else None
            ),
            comment=core.Stack.of(self).stack_name,
            origin_configs=[
                # Frontend Website
                cf.SourceConfiguration(
                    # NOTE: Can't use S3OriginConfig because we want to treat our
                    # bucket as an S3 Website Endpoint rather than an S3 REST API
                    # Endpoint. This allows us to use a custom error document to
                    # direct all requests to a single HTML document (as required
                    # to host an SPA).
                    custom_origin_source=cf.CustomOriginConfig(
                        domain_name=website_bucket.bucket_website_domain_name,
                        origin_protocol_policy=cf.OriginProtocolPolicy.HTTP_ONLY,  # In website-mode, S3 only serves HTTP # noqa: E501
                    ),
                    behaviors=[cf.Behavior(is_default_behavior=True)],
                ),
                # API load balancer
                cf.SourceConfiguration(
                    custom_origin_source=cf.CustomOriginConfig(
                        domain_name=api_lb.load_balancer_dns_name,
                        origin_protocol_policy=cf.OriginProtocolPolicy.HTTP_ONLY,
                    ),
                    behaviors=[
                        cf.Behavior(
                            path_pattern="/api*",  # No trailing slash to permit access to root path of API # noqa: E501
                            allowed_methods=cf.CloudFrontAllowedMethods.ALL,
                            forwarded_values={
                                "query_string": True,
                                "headers": [
                                    "referer",
                                    "authorization",
                                    "origin",
                                    "accept",
                                    "host",  # Required to prevent API's redirects on trailing slashes directing users to ALB endpoint # noqa: E501
                                ],
                            },
                            # Disable caching
                            default_ttl=core.Duration.seconds(0),
                            min_ttl=core.Duration.seconds(0),
                            max_ttl=core.Duration.seconds(0),
                        )
                    ],
                ),
                # Assets
                cf.SourceConfiguration(
                    s3_origin_source=cf.S3OriginConfig(
                        s3_bucket_source=assets_bucket, origin_access_identity=oai,
                    ),
                    behaviors=[
                        cf.Behavior(
                            path_pattern="/storage/*", trusted_signers=["self"],
                        )
                    ],
                ),
            ],
        )
        self.assets_path = f"https://{self.distribution.domain_name}/storage"
        core.CfnOutput(self, "Endpoint", value=self.distribution.domain_name)

    def grant_oai_read(self, oai: cf.OriginAccessIdentity, bucket: s3.Bucket):
        """
        To grant read access to our OAI, at time of writing we can not simply use
        `bucket.grant_read(oai)`. This is due to the fact that we are looking up
        our bucket by its name. For more information, see the following:
        https://stackoverflow.com/a/60917015/728583.

        As a work-around, we can manually assigned a policy statement, however
        this does not work in situations where a policy is already applied to
        the bucket (e.g. in GCC environments).
        """
        policy_statement = iam.PolicyStatement(
            actions=["s3:GetObject*", "s3:List*"],
            resources=[bucket.bucket_arn, f"{bucket.bucket_arn}/storage*"],
            principals=[],
        )
        policy_statement.add_canonical_user_principal(
            oai.cloud_front_origin_access_identity_s3_canonical_user_id
        )
        assets_policy = s3.BucketPolicy(self, "AssetsPolicy", bucket=bucket)
        assets_policy.document.add_statements(policy_statement)
```

</details>

## Additional reading

- [Amazon S3 + Amazon CloudFront: A Match Made in the Cloud](https://aws.amazon.com/blogs/networking-and-content-delivery/amazon-s3-amazon-cloudfront-a-match-made-in-the-cloud/)
- [Dynamic Whole Site Delivery with Amazon CloudFront](https://aws.amazon.com/blogs/networking-and-content-delivery/dynamic-whole-site-delivery-with-amazon-cloudfront/)
