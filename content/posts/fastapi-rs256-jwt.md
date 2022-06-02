---
date: 2022-05-27
layout: post
title: Securing FastAPI with JWKS (AWS Cognito, Auth0)
categories: ["posts"]
tags: [auth, fastapi, python]
---

This post is a quick capture of how to easily secure your FastAPI with any auth provider that provides [JWKS](https://auth0.com/docs/secure/tokens/json-web-tokens/json-web-key-sets).

### Background: RS256

RS256 is a signing algorithm used to generate and validate JSON Web Tokens (JWTs).  Unlike the common HS256 algorithm that uses the same secret string to both generate and validate JWTs, RS256 uses a private key to generate JWTs and a separate public key for validating JWTs:

> RS256 generates an asymmetric signature, which means a private key must be used to sign the JWT and a different public key must be used to verify the signature. [[source](https://auth0.com/docs/secure/tokens/json-web-tokens/json-web-key-sets)]

This allows you to share your public key and thus enables any service to validate JWTs (provided that the service can read the public key).  This makes RS256 a great choice for distributed applications, wherein one service generates auth tokens but many services can independently validate auth tokens.  

_Note:_ You are already using asymmetric cryptographic algorithms. For example, when you access a website over HTTPS, the SSL certificate includes a public key to allow a browser to validate messages sent by the origin server, while the origin server maintains a private key used to sign messages before they are sent.  Additionally, when you set up SSH key pair for the purpose of connecting to servers, this key pair consists of a _private_ and _public_ key.  The private is kept on your machine while a public key can be stored in a `~/.ssh/authorized_keys` file on the server to validate login requests.

### Background: JWKS

> The JSON Web Key Set (JWKS) is a set of keys containing the public keys used to verify any JSON Web Token (JWT) issued by the authorization server and signed using the RS256 [signing algorithm](https://auth0.com/docs/get-started/applications/signing-algorithms). [[source](https://auth0.com/docs/secure/tokens/json-web-tokens/json-web-key-sets)]

The JWKS is needed by each service that will be validating tokens.  It can be commonly be found at `/.well-known/jwks.json`, however theoretically could be distributed in any other means (S3, AWS Parameter Store, etc).

#### JWKS Locations

Provider | Location | Example
--- | --- | ---
Cognito | `https://cognito-idp.{region}.amazonaws.com/{user_pool_id}/.well-known/jwks.json` | https://cognito-idp.us-east-1.amazonaws.com/us-east-1_Wt2sA2K9e/.well-known/jwks.json
Auth0 | `https://YOUR_DOMAIN/.well-known/jwks.json` | https://example.auth0.com/.well-known/jwks.json

### FastAPI Integration

For a FastAPI application to validate a JWT signed with an RS256 algorithm, it needs to do the following:

1. Load JWKS
2. Retrieve token from the request
3. Validate the token's signature against the JWKS

Below, I've added a simple way to achieve this by taking advantage of [FastAPI's dependency injection system](https://fastapi.tiangolo.com/tutorial/dependencies/) and [Authlib](https://docs.authlib.org/en/latest/):

```py
import logging
from functools import lru_cache

from authlib.jose import JsonWebToken, JsonWebKey, KeySet, JWTClaims, errors
from cachetools import cached, TTLCache
from fastapi import FastAPI, Depends, HTTPException, security
import requests
import pydantic

logger = logging.getLogger(__name__)

token_scheme = security.HTTPBearer()


class Settings(pydantic.BaseSettings):
    cognito_user_pool_id: str

    @property
    def jwks_url(self):
        """
        Build JWKS url
        """
        pool_id = self.cognito_user_pool_id
        region = pool_id.split("_")[0]
        return f"https://cognito-idp.{region}.amazonaws.com/{pool_id}/.well-known/jwks.json"


@lru_cache()
def get_settings() -> Settings:
    """
    Load settings (once per app lifetime)
    """
    return Settings()


def get_jwks_url(settings: Settings = Depends(get_settings)) -> str:
    """
    Get JWKS url
    """
    return settings.jwks_url


@cached(TTLCache(maxsize=1, ttl=3600))
def get_jwks(url: str = Depends(get_jwks_url)) -> KeySet:
    """
    Get cached or new JWKS. Cognito does not seem to rotate keys, however to be safe we
    are lazy-loading new credentials every hour.
    """
    logger.info("Fetching JWKS from %s", url)
    with requests.get(url) as response:
        response.raise_for_status()
        return JsonWebKey.import_key_set(response.json())


def decode_token(
    token: security.HTTPAuthorizationCredentials = Depends(token_scheme),
    jwks: KeySet = Depends(get_jwks),
) -> JWTClaims:
    """
    Validate & decode JWT.
    """
    try:
        return JsonWebToken(["RS256"]).decode(s=token.credentials, key=jwks)
    except errors.JoseError:
        logger.exception("Unable to decode token")
        raise HTTPException(status_code=403, detail="Bad auth token")


app = FastAPI()


@app.get("/who-am-i")
def who_am_i(claims=Depends(decode_token)) -> str:
    """
    Return claims for the provided JWT
    """
    return claims


@app.get("/auth-test", dependencies=[Depends(decode_token)])
def auth_test() -> bool:
    """
    Require auth but not use it as a dependency
    """
    return True

```
