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

Below, I've added a simple way to achieve this by taking advantage of [FastAPI's dependency injection system](https://fastapi.tiangolo.com/tutorial/dependencies/) and [pyJWT](https://pyjwt.readthedocs.io):

```py
from typing import Annotated, Any, Dict, List, Optional

import jwt
from fastapi import FastAPI, HTTPException, Security, security, status
from pydantic import HttpUrl
from pydantic_settings import BaseSettings


#
# Settings
#
class Settings(BaseSettings):
    authorization_url: HttpUrl
    token_url: HttpUrl
    jwks_url: HttpUrl
    client_id: str
    permitted_jwt_audiences: List[str] = ["account"]


settings = Settings(
    # Some example Cognito endpoints...
    authorization_url="https://example-app.auth.us-west-2.amazoncognito.com/oauth2/authorize",
    token_url="https://example-app.auth.us-west-2.amazoncognito.com/oauth2/token",
    jwks_url="https://cognito-idp.us-west-2.amazonaws.com/us-west-2_3x4mP1e1d/.well-known/jwks.json",
    client_id='example-api'
)
jwks_client = jwt.PyJWKClient(settings.jwks_url)  # Caches JWKS


#
# Dependencies
#
oauth2_scheme = security.OAuth2AuthorizationCodeBearer(
    authorizationUrl=settings.authorization_url,
    tokenUrl=settings.token_url,
    scopes={ # Populate UI for scope selection checkboxes
        f"example:{resource}:{action}": f"{action.title()} {resource}"
        for resource in ["note"]
        for action in ["create", "read", "update", "delete"]
    },
)


def user_token(
    token_str: Annotated[str, Security(oauth2_scheme)],
    required_scopes: security.SecurityScopes,
):
    # Parse & validate token
    try:
        token = jwt.decode(
            token_str,
            jwks_client.get_signing_key_from_jwt(token_str).key,
            algorithms=["RS256"],
            audience=settings.permitted_jwt_audiences,
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Could not validate credentials",
            headers={"WWW-Authenticate": "Bearer"},
        ) from e

    # Validate scopes (if required)
    for scope in required_scopes.scopes:
        if scope not in token["scope"]:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Not enough permissions",
                headers={
                    "WWW-Authenticate": f'Bearer scope="{required_scopes.scope_str}"'
                },
            )

    return token


#
# App
#
app = FastAPI(
    docs_url="/",
    swagger_ui_init_oauth={
        "appName": "ExampleApp",
        "clientId": settings.client_id,
        "usePkceWithAuthorizationCodeGrant": True,
    },
)


@app.get("/my-token")
def token(user_token: Annotated[Dict[Any, Any], Security(user_token)]):
    """View auth token token."""
    return user_token


@app.get("/my-scopes")
def scopes(user_token: Annotated[Dict[Any, Any], Security(user_token)]):
    """View auth token scopes."""
    return user_token["scope"].split(" ")


@app.get(
    "/notes",
    dependencies=[Security(user_token, scopes=["example:note:read"])],
)
def read_note():
    """Mock endpoint to read a note. Requires `example:note:read` scope."""
    return {
        "success": True,
        "details": "🚀 You have the required scope to read a note",
    }


@app.post(
    "/notes",
    dependencies=[Security(user_token, scopes=["example:note:create"])],
)
def create_note():
    """Mock endpoint to create a note. Requires `example:note:create` scope."""
    return {
        "success": True,
        "details": "🚀 You have the required scope to create a note",
    }

```
