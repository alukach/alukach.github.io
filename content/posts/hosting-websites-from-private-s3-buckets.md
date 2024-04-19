---
date: 2024-04-19
layout: post
title: Hosting websites from private S3 buckets
categories: ["snippets"]
tags: [aws, s3]
---

At work, we were alerted to an outage of an S3-backed frontend. The frontend was returning 403 responses. This left us scratching our head, as no deployment had occurred recently. After doing some digging, we found that AWS account administrators had applied a new policy to make all S3 buckets private (this is an account-wide setting, overriding bucket-level settings). 🆒 🆒 🆒 

### So how can we configure Cloudfront to access private S3 buckets? 

After a bit of experimentation, here are my findings:

* When using a Cloudfront Distribution, you can not use an Origin Access Control (OAC) to connect to a private S3 bucket’s _website endpoint_ (i.e.  `{bucket_name}.s3-website-{bucket_region}.amazonaws.com`).
* When using a Cloudfront Distribution, you can use an Origin Access Control (OAC) to connect to a private S3 bucket’s non-website endpoint (i.e.  `{bucket_name}.s3.{bucket_region}.amazonaws.com`), however you lose some of the functionality that the S3 website endpoint brings such as the handling of routes without extensions (i.e. a request to `/foo` will not load `/foo/index.html`).

#### Some notes

* Allegedly, the legacy Cloudfront `WebDistribution` allowed using an Origin Access Identity (OAI) with website buckets ([source](https://repost.aws/questions/QUnzTuF8y7StOBK-QY_RTGkA/oai-or-not-oai-for-serving-a-static-website-in-s3-using-cloudfront#ANDedpud0CTnSqi4WNRUr_LQ)), however this is now deprecated in favor of the Cloudfront `Distribution` API and does not appear to be available via the AWS Console UI.
* The server handling of routes without extensions can be recreated for non-website S3 origins by use of a viewer-request Cloudfront Function ([code](https://stackoverflow.com/questions/31017105/how-do-you-set-a-default-root-object-for-subdirectories-for-a-statically-hosted/69157535#69157535)) 
* Some docs seem to suggest that you must set a “Default root object” on the entire distribution when serving an S3 origin, but in my experimentation this appears to be a solution for only root-level path rewriting (i.e. requests to `/` are sent to `/index.html`) but will not aide in adding the same logic to nested pathed (e.g. requests to `/foo/` will not be sent to `/foo/index.html`). If a custom CF function is used to perform the path rewriting, a "Default root object" does not appear to be necessary.

### TLDR

Therefore, to host an S3 website from a private S3 bucket via Cloudfront, one must:

1. Create a Cloudfront Origin pointing to the S3 non-website endpoint (i.e. `{bucket_name}.s3.{bucket_region}.amazonaws.com`)
1. Create an Origin Access Control
1. Update the S3 bucket with a policy permitting the OAC access (Cloudfront will provide you with this policy)
1. Add a `viewer-request` Cloudfront Function that rewrites requests to endpoints without extensions. See below for code that will handle this rewrite. _Note that it does not replicate the redirect-header functionality that S3 website buckets offer. That is okay in most cases._
    ```
    function handler(event) {
        var request = event.request;
        var uri = request.uri;
        
        // Check whether the URI is missing a file name.
        if (uri.endsWith('/')) {
            request.uri += 'index.html';
        } 
        // Check whether the URI is missing a file extension.
        else if (!uri.includes('.')) {
            request.uri += '/index.html';
        }
    
        return request;
    }
    ```


> [!WARNING]
> 
> 
> The above technique is not a panacea by any means.  There may be situations where this falls short for an applications needs.  When looking at whether a static website can be hosted via a private bucket, consider the following:
> 
> 1. **Routes without file paths:** We need server-side logic to take requests to endpoints like `/about` and direct that lookup to `/about/index.html`. This is achievable via S3's website endpoint or a CF function that adds that logic. *QA tip*: Be sure to thoroughly test this, you may be able to navigate from [example.com/](http://example.com/) to [example.com/about](http://example.com/about) because React.JS is handling the route navigation at that point, but does the website work if you type `<https://example.com/about>` in the URL?
> 2. **Dynamic routes:** the trick for supporting dynamic routes (e.g. `/articles/{article-id}`) in an SPA on S3 is to use a custom error page that serves the application (for more info, see "Single Page Applications" of [this post](/posts/using-cloudfont-as-a-reverse-proxy/)). This is achievable via S3's website endpoint, but not for a CF function unless something very clever is done. This issue won't apply to websites that are fully statically generate (ie SSG, such as some Gatsby or NextJS applications). **I don't think this is reasonably possible for private buckets.**
> 3. **Redirects:** S3's website endpoint has a feature where it will return any object with the `x-amz-website-redirect-location` metadata property as a `302` redirect to a new location. This will be lost when not using the S3 website endpoint unless a custom `viewer-response` CF function is implemented.
> 4. **Custom Error Page:** S3's website endpoint has a feature where it will return a user-specified document as a 404 document for any object that is requested but not found. This is the basis of the dynamic-route trick mentioned above. Even when not relying on the dynamic-route trick, it is nice to serve a user a well-formatted error page to inform them that they have followed a bad route.  When using the S3 non-website endpoint, an XML-formatted error will instead be served.  Cloudfront does have the ability to serve a custom user-specified custom error pages (e.g. 404s) for the entire distribution, however this is very likely non-ideal in that we may not want to serve the same format of 404 for a JSON-based REST API as we would for a HTML-based web application (for more info, see "Single Page Applications" of [this post](/posts/using-cloudfont-as-a-reverse-proxy/))