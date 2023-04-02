---
date: 2023-04-01
layout: post
title: Notes on Cookies 🍪
categories: ["posts"]
tags: [cookies]
---

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
3. Backend must specify that our cookies have `samesite=none` so that they can be shared across sites. Note that `Secure` must also be set when `samesite=none` ([docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite)).
4. Backend must skip auth checks on preflight requests, as browsers don’t send cookies on `OPTIONS` requests ([docs](https://www.w3.org/TR/2020/SPSD-cors-20200602/#cross-origin-request-with-preflight-0))
3. Frontend needs to provide credentials with requests.
    ```js
    await fetch(url, { credentials: true });
    ```
