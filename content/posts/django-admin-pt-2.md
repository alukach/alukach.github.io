---
date: 2014-12-15
layout: post
title: Django Admin Fu, part 2
categories: ["posts"]
tags: [django]
---

Continuing with the [Django Admin Fu post part 1]({{< relref django-admin-pt-1 >}}).

## Action with Intermediate Page

Sometimes you may need an admin action that, when submitted, takes the user to a form where they provides some additional detail. The docs mention [a bit](https://docs.djangoproject.com/en/1.6/ref/contrib/admin/actions/#actions-that-provide-intermediate-pages) about providing intermediate pages, but not a lot.  It states:

> Generally, something like [writing a intermediate page through the admin] isn’t considered a great idea. Most of the time, the best practice will be to return an HttpResponseRedirect and redirect the user to a view you’ve written, passing the list of selected objects in the GET query string. This allows you to provide complex interaction logic on the intermediary pages.

I do see where the docs are coming from and it would probably be easier to do as advised, but I think there could be something said about keeping all admin logic within the admin page. Doing something like the following would take the user to an intermediate form.

{{< gist alukach 30422de26d25295f6289 >}}
