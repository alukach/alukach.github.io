---
layout: post
title: Hosting Jupyter at a subdomain via Cloudflare
category: posts
tags: [jupyter, machine-learning, cloudflare, devops]
---

_Full Disclosure: I am NOT an expert at Jupyter or Anaconda (which I am using in this project), there may be some bad habits below..._

Below is a quick scratchpad of the steps I took to serve Jupyter from a subdomain. Jupyter is running behind NGINX on an OpenStack instance and the domain's DNS is set up to use Cloudflare to provides convenient SSL support. I was suprised by the lack of documentation for this process, prompting me to document my steps taken here.

## Cloudflare

1. Set up Cloudflare account, utilizing its provided Name Servers with my domain registration.
2. Set up Cloudflare DNS Record for subdomain (ex `jupyter` to server from `jupyter.mydomain.com`). In the image below, the DNS entry for the Jupyter server was "greyed-out", relegating it to "DNS Only" rather than "DNS and HTTP Proxy (CDN)".. Now that [Cloudflare supports websockets](https://support.cloudflare.com/hc/en-us/articles/200169466-Can-I-use-CloudFlare-with-WebSockets-), this is no longer necessary and you're able to take advantage of using Cloudflare as a CDN (admittedly, I'm not sure how useful this actually is, but it's worth mentioning).
![Setting up DNS Record](/images/2016-12-28-jupyter/manage_dns.png)
3. Ensure Crypto settings are set correctly. You should probably be using [Full SSL (Strict)](https://blog.cloudflare.com/introducing-strict-ssl-protecting-against-a-man-in-the-middle-attack-on-origin-traffic/) rather than Flexible SSL as shown in the image below, however that is outside the scope of this post.
![SSL Settings](/images/2016-12-28-jupyter/ssl_settings.png)
![Auto-rewrite to HTTPS](/images/2016-12-28-jupyter/https_rewrite.png)

## Set up an Upstart script

On the server, you'll want Jupyter to start running as soon as the server starts.  We'll use an Upstart script to acheive this.

{% highlight upstart %}
# /etc/init/ipython-notebook.conf
start on filesystem or runlevel [2345]
stop on shutdown

# Restart the process if it dies with a signal
# or exit code not given by the 'normal exit' stanza.
respawn

# Give up if restart occurs 10 times in 90 seconds.
respawn limit 10 90

description "Jupyter / IPython Notebook Upstart script"

script
    export HOME="/home/MY_USER/notebooks"; cd $HOME
    echo $$ > /var/run/ipython_start.pid
    exec su -s /bin/sh -c 'exec "$0" "$@"' MY_USER -- /home/MY_USER/.anaconda3/bin/jupyter-notebook --config='/home/MY_USER/.jupyter/jupyter_notebook_config.py'
end script
{% endhighlight %}

## Configure Jupyter

Populate Jupyter with required configuration. You should probably auto-generate the configuration first and then just change the applicable variables.

{% highlight python %}
# .jupyter/jupyter_notebook_config.py
c.NotebookApp.allow_origin = 'https://jupyter.mydomain.com'
c.NotebookApp.notebook_dir = '/home/MY_USER/notebooks'
c.NotebookApp.open_browser = False
c.NotebookApp.password = 'some_password_hash'
c.NotebookApp.port = 8888
c.NotebookApp.kernel_spec_manager_class = "nb_conda_kernels.CondaKernelSpecManager"
c.NotebookApp.nbserver_extensions = {
  "nb_conda": True,
  "nb_anacondacloud": True,
  "nbpresent": True
}
{% endhighlight %}

## Wire Jupyter up with Nginx

To be able to access Jupyter at port 80, we'll need to reverse proxy to the service. Nginx can take care of this for us.  Jupyter uses websockets to stream data to the client, so some

{% highlight nginx %}
# /etc/nginx/sites-enabled/jupyter.conf
# Based on example: https://gist.github.com/cboettig/8643341bd3c93b62b5c2
upstream jupyter {
    server 127.0.0.1:8888 fail_timeout=0;
}

 map $http_upgrade $connection_upgrade {
     default upgrade;
     '' close;
 }

server {
    listen 80 default_server;
    listen [::]:80 default_server ipv6only=on;

    # Make site accessible from http://localhost/
    server_name localhost;

    client_max_body_size 50M;

    location / {
        proxy_pass http://jupyter;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location ~* /(api/kernels/[^/]+/(channels|iopub|shell|stdin)|terminals/websocket)/? {
        proxy_pass http://jupyter;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # WebSocket support
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
    }
}
{% endhighlight %}

