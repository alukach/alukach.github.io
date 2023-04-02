---
date: 2023-04-01
layout: post
title: Everything that I know about Cookies 🍪
categories: ["posts"]
tags: [cookies]
---

# Everything that I know about Cookies 🍪

This is everything that I know about using cookies in web applications.  Admittedly, I don't know a lot about cookies and should probably not be considered a source of authority on this topic.  These are just notes I write while trying to learn about the subject.

## Why cookies?

### Making authenticated anchor tags

Can't specify headers with `<a>` tags.

Could supply token as query parameter, but that's a security concern due to potential of token being cached with URL.

### No need to manage JWTs within your application

Things that are annoying about JWTs when building frontend applications:

* You need to choose where to store the JWTs
* You need to supply the JWT to any code making API requests

## How cookies?

Cookies are typically set by backend code after a user successfully logs in.  They are typically signed to verify their authenticity.  Often, they simply point to an identifier that tracks a user's "session"  within some stateful service (ie database).

## How to do cross-origin cookies


1. Backend must set the `access-control-allow-credentials` header to instruct browsers that it’s okay to pass credentials when making requests ([docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Credentials)).
2. Backend must specify the `access-control-allow-origin` header to instruct browsers which origins are allowed to access the API. Note that you can’t use * when passing credentials between origins ([docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS/Errors/CORSNotSupportingCredentials)).
3. Backend will need to specify that our cookies have `samesite=none` if attempting to send cookie to other sites. Note that `Same-Site` is not the same as `Same-Origin`, a cookie set with `samesite=strict` will still be passed between hosts at different subdomains or ports of a domain ([docs](https://web.dev/same-site-same-origin/)).   Also note that `Secure` must also be set when `samesite=none` ([docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite)).
5. Backend must skip auth checks on preflight requests, as browsers don’t send cookies on `OPTIONS` requests ([docs](https://www.w3.org/TR/2020/SPSD-cors-20200602/#cross-origin-request-with-preflight-0))
6. Frontend needs to provide credentials with requests.
    ```js
    await fetch(url, { credentials: true });
    ```
