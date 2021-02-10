---
date: 2014-11-04
layout: post
title: Django Admin Fu, part 1
category: posts
tags: [django]
---

I've been putting some time into building out the Django Admin site for one of my company's projects. Here are some notes I've taken about straying away from the beaten path. I find surprisingly little information about how to do these things on StackOverflow or elsewhere. These were put used when working with [Django 1.6.7](https://docs.djangoproject.com/en/1.6/).

## Fake The Model, Make The View

You may want a form on the Django Admin that exists along side the model views but doesn't actually represent a model.  This strays somewhat from what the Django Admin is set up to do (some on the `#django` channel on Freenode have stated that the admin should only be for CRUD operations on Django models.)  None-the-less, if you do want to inject a form into the admin along side your models, this is a method that worked for me.

It revolves around generating a fake model that you register to your app's admin view. After that, you create a model admin that inherits from the standard `ModelAdmin`.

{{< gist alukach 0a1b9e788b805e6242dd >}}

See more in [part 2]({% post_url 2014-12-15-django-admin-pt-2 %}).
