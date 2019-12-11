---
layout: post
title: A PIL-friendly class for S3 objects
category: posts
tags: [python, pil, s3]
---

Here's a quick example of creating an file-like object in Python that represents an object on S3 and plays nicely with [PIL](http://pillow.readthedocs.io/).  This ended up being overkill for my needs but I figured somebody might get some use out of it.

{% gist 7266c77e9990307e492516a6b8990c63 %}
