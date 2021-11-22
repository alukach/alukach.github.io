---
date: 2018-03-28
layout: post
title: Serve an Esri Web AppBuilder web app from HTTP
categories: ["snippets"]
tags: [esri]
---


When an [Esri Web AppBuilder](https://www.esri.com/en-us/arcgis/products/web-appbuilder/overview) web app is configured with a `portalUrl` value served from HTTPS, the web app automatically redirects users to HTTPS when visited via HTTP. While this is best-practice in production, it can be a burden in development when you want to quickly run a local version of the web app. Below is a quick script written with Python standard libraries to serve a web app over HTTP. It works by serving a `config.json` that is modified to use HTTP rather than HTTPS. This allows you to keep `config.json` using the HTTPS configuration for production but serve the web app via HTTP during development.

{{< gist alukach 8d7d50e05306aa2b81eac64a04a6d8ba >}}

The script should be saved alongside the `config.json` in the root of the web app. I would recommend running `chmod a+x runserver` to enable you to execute the server directly via `./runserver`. Alternatively, you could install this somewhere on your system path to invoke from any directory (something like `cp runserver /usr/local/bin/serve-esri-app` for a unix-based system).
