---
date: 2024-02-08
layout: post
title: An ECS -> RDS Securty Group Script
categories: ["snippets"]
tags: [ecs,rds,aws]
---

Below is a simple script to allow a user to alter RDS databases security groups to allow access from an ECS Service.  Useful when we have an observability tool runing in ECS that wants to add RDS data connections.


```py
from typing import List, Dict

import boto3
from botocore.exceptions import ClientError
import inquirer


def list_ecs_clusters():
    ecs = boto3.client("ecs")
    clusters = ecs.list_clusters()
    cluster_arns = clusters["clusterArns"]
    return cluster_arns


def list_ecs_services(cluster) -> List[str]:
    ecs = boto3.client("ecs")
    services = ecs.list_services(cluster=cluster)
    return services["serviceArns"]


def list_rds_instances() -> Dict[str, str]:
    rds = boto3.client("rds")
    return rds.describe_db_instances()["DBInstances"]


def get_security_group_from_ecs_service(cluster, service_arn) -> str:
    ecs = boto3.client("ecs")
    details = ecs.describe_services(cluster=cluster, services=[service_arn])
    service = details["services"][0]
    deployment = service["deployments"][0]
    groups = deployment["networkConfiguration"]["awsvpcConfiguration"]["securityGroups"]
    return groups[0]


def get_security_group_ids_for_rds_instance(instance_identifier) -> List[str]:
    """
    Returns the security group IDs for a given RDS instance identifier.

    :param instance_identifier: The identifier of the RDS instance
    :return: A list of security group IDs associated with the RDS instance
    """
    rds = boto3.client("rds")
    try:
        response = rds.describe_db_instances(DBInstanceIdentifier=instance_identifier)
        db_instances = response["DBInstances"]
        if db_instances:
            # Assuming each instance has at least one security group associated with it
            security_groups = db_instances[0]["VpcSecurityGroups"]
            security_group_ids = [sg["VpcSecurityGroupId"] for sg in security_groups]
            return security_group_ids
        else:
            return []
    except Exception as e:
        print(
            f"Error fetching security group IDs for RDS instance '{instance_identifier}': {e}"
        )
        return []


def modify_security_group_rules(
    security_group_id,
    protocol,
    from_port,
    to_port,
    source_security_group_id: str,
    description: str,
    dry_run: bool,
) -> None:
    ec2 = boto3.client("ec2")
    if dry_run:
        print(
            f"Dry run: Would update Security Group {security_group_id} "
            f"to allow from {source_security_group_id}"
        )
        return

    try:
        ec2.authorize_security_group_ingress(
            GroupId=security_group_id,
            IpPermissions=[
                {
                    "IpProtocol": protocol,
                    "FromPort": from_port,
                    "ToPort": to_port,
                    "UserIdGroupPairs": [
                        {
                            "GroupId": source_security_group_id,
                            "Description": description,
                        }
                    ],
                }
            ],
        )
        print(f"Security Group {security_group_id} updated successfully.")
    except ClientError as e:
        print(f"Error updating Security Group: {e}")


if __name__ == "__main__":
    dry_run = inquirer.confirm(
        message="Dry run (no changes will be made)?",
        default=True,
    )

    ecs_clusters = list_ecs_clusters()
    rds_instances = list_rds_instances()

    # Select ECS Cluster
    resource_questions = [
        inquirer.List(
            "cluster",
            message="Select ECS Cluster that contains the service that requires databases access",
            choices=ecs_clusters,
            default=lambda answers: next(
                (cluster for cluster in ecs_clusters if "grafana" in cluster), None
            ),
        ),
        inquirer.List(
            "service",
            message="Select ECS Service in {cluster} that requires database access",
            choices=lambda answers: list_ecs_services(answers["cluster"]),
        ),
        inquirer.Checkbox(
            "rds_instances",
            message="Select RDS Instances that will be accessed via {cluster}/{service}",
            choices=[instance["DBInstanceIdentifier"] for instance in rds_instances],
        ),
        inquirer.Text(
            "description",
            message="Provide a description for the connection",
            default=lambda answers: f"Allow connections from ECS service: {answers['service'].split(':')[-1]}",
        ),
    ]

    resources = inquirer.prompt(resource_questions)

    # Get Security Group of selected RDS Instances
    rds_security_groups = [
        get_security_group_ids_for_rds_instance(rds_instance)[0]
        for rds_instance in resources["rds_instances"]
    ]

    # Get Security Group of selected ECS Service
    ecs_security_group = get_security_group_from_ecs_service(
        resources["cluster"], resources["service"]
    )

    # Update RDS Instances' Security Groups to allow inbound connections from ECS Service
    print(f"{rds_security_groups=}")
    for rds_sg_id in rds_security_groups:
        modify_security_group_rules(
            security_group_id=rds_sg_id,
            source_security_group_id=ecs_security_group,
            protocol="tcp",
            to_port=5432,
            from_port=5432,
            description=resources["description"],
            dry_run=dry_run,
        )
```
