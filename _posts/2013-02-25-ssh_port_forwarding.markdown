---
layout: post
title: SSH Port Forwarding
category: posts
tags: [command-line-foo]
---

The other week I found myself up at 2am in Canada setting up a VPN between my home computer (running Ubuntu) in Seattle and my laptop <partyhard.jpg>.  I had enabled SSH access on my home computer and had set up port forwarding on my router to allow for access from the outside world ahead of time, but had forgotten that I would need to have a port forwarded for the VPN server as well.  I tried to SSH into my home box and access the router's admin interface from the commandline browser (using [Lynx](http://packages.ubuntu.com/search?keywords=lynx) and [w3m](http://packages.ubuntu.com/search?keywords=w3m)).  This was a bad idea and didn't work, as the browser's admin page required JavaScript for some odd reason.

And then I remembered this command:

{% highlight bash %}
ssh -D 8080 -Nf login@server.whatever.com
{% endhighlight %}

Pointed my browser's connection settings to SOCKS proxy with server as 'localhost' and port at '8080' and BOOM, was able to access my Seattle home's router's config page from Canada.  I've found this trick useful for all sorts of things, typically for one-offs where I need to access a website from the US while in Canada.
