---
date: 2021-09-17
layout: post
title: SSH tunnels in Python
categories: ["snippets"]
tags: [python, ssh, tunnel]
---


At times, a developer may need to access infrastructure not available on the public internet.  A common example of this is accessing a database located in a private subnet, as described in the [VPC Scenario docs](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Scenario2.html):

> Instances in the private subnet are back-end servers that don't need to accept incoming traffic from the internet and therefore do not have public IP addresses; however, they can send requests to the internet using the NAT gateway.

The common strategy for connecting to one of these devices is to tunnel your traffic through a [jump box AKA jump server AKA jump host](https://en.wikipedia.org/wiki/Jump_server).  This can be achieved by [SSH Port Forwarding AKA SSH Tunneling](https://www.ssh.com/academy/ssh/tunneling/example).  

For a recent project, I needed a convenient way to query private databases in Python to do some repeatable data management operations. Tools like [DBeaver](https://dbeaver.io/) have built-in support for connecting to databases over SSH tunnels, however I needed something more scriptable. Standing up a service in AWS would have worked however seemed to be overkill for my simple scripting needs.  My goals were to 1) get auth credentials from AWS Secrets Manager (RDS places credentials in Secrets Manager by default, or at least when creating RDS instances via CDK); 2) setup a tunnel through a jumpbox to allow access to the RDS Instance; 3) run SQL queries against the DB.  Automating this process in Python was not immediately clear until found the [`sshtunnel` module](https://sshtunnel.readthedocs.io/en/latest/).  After playing around with the code for a bit, I was able to put together a utility class with [Pydantic](http://pydantic-docs.helpmanual.io/) and [Psycopg2](https://www.psycopg.org/docs/) to conveniently connect to a private RDS instance via SSH tunneling.  I figured I would share in the event that someone ever needs such a tool in the future.

### Code Sample

```py
import socket
import contextlib
import logging
from typing import Any, Generator, Tuple, Optional

import psycopg2
import psycopg2.extras
from pydantic.main import BaseModel
from sshtunnel import open_tunnel

logger = logging.getLogger(__name__)


class Db(BaseModel):
    dbname: str
    user: str
    password: str
    host: str
    port: int = 5432

    @contextlib.contextmanager
    def cursor(
        self, name=None
    ) -> Generator[Tuple[Any, psycopg2.extras.DictCursor], None, None]:
        logger.debug("Connecting to %s", self.dbname)
        with psycopg2.connect(**self.dict()) as conn:
            cursor = conn.cursor(name, cursor_factory=psycopg2.extras.DictCursor)
            with cursor as curs:
                logger.debug("Yielding cursor")
                yield conn, curs
                logger.debug("Disconnecting from %s", self.dbname)

    @contextlib.contextmanager
    def create_tunnel(
        self,
        jumpbox_host: str,
        local_port: Optional[int] = None,
        jumpbox_port: int = 22,
        local_host: str = "127.0.0.1",
        jumpbox_username: str = None,
        ssh_key_password: str = None,
    ) -> Generator["Db", None, None]:
        """
        Generates an SSH tunnel to DB via jumpbox.
        """
        if local_port is None:
            local_port = self._find_free_port()
        with open_tunnel(
            (jumpbox_host, jumpbox_port),
            ssh_username=jumpbox_username,
            remote_bind_address=(self.host, self.port),
            local_bind_address=(local_host, local_port),
            ssh_private_key_password=ssh_key_password,
        ) as tunnel:
            logger.debug(
                "Tunnel to %s through %s established on port %s",
                self.host,
                jumpbox_host,
                local_port,
            )
            yield self.copy(
                update={
                    "host": tunnel.local_bind_host,
                    "port": tunnel.local_bind_port,
                }
            )

    @classmethod
    def from_rds_credentials(cls, secret):
        return cls.parse_obj({"user": secret.pop("username"), **secret})

    @staticmethod
    def _find_free_port() -> int:
        # https://stackoverflow.com/a/45690594/728583
        with contextlib.closing(socket.socket(socket.AF_INET, socket.SOCK_STREAM)) as s:
            s.bind(("", 0))
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            return s.getsockname()[1]


if __name__ == "__main__":
    import json
    import boto3

    rds_secret = "myRdsDbSecret"  # ARN or Secret ID
    jumpbox_host = "my-jumpbox-hostname"  # hostname/ip address of jumpbox

    credentials = json.load(
        boto3.client("secretsmanager").get_secret_value(SecretId=rds_secret)[
            "SecretString"
        ]
    )
    private_db = Db.from_rds_credentials(credentials)
    with private_db.create_tunnel(jumpbox_host) as db:
        with db.cursor() as (conn, cur):
            cur.execute("SELECT COUNT(*) FROM my_table;")
            print(cur.fetchone())

```
