---
date: 2024-03-21
layout: post
title: ".env to Github Environment Variables"
categories: ["snippets"]
tags: [github, actions, dotenv]
---

A script for uploading dotenv files to Github environments:


```sh
#!/bin/bash
PAGER=""  # Avoid pager when using zsh

# Check if the correct number of arguments are passed
if [ "$#" -ne 2 ]; then
    echo "Usage: $0 <org/repo> <environment> < .env"
    exit 1
fi

# Parse arguments
ORG_REPO=$1
ENVIRONMENT_NAME=$2
echo "ORG_REPO: $ORG_REPO"
echo "ENVIRONMENT_NAME: $ENVIRONMENT_NAME"

# Get repository ID
REPOSITORY_ID=$(gh api /repos/$ORG_REPO --jq '.id')
if [ -z "$REPOSITORY_ID" ]; then
    echo "Error: Repository ID for $ORG/$REPO could not be found." >&2
    exit 1
fi
echo "REPOSITORY_ID: $REPOSITORY_ID"

# Read from standard input
while read -r line || [[ -n "$line" ]]; do
  # Skip empty and comment lines
  if [[ -z "$line" || "${line:0:1}" == "#" ]]; then
    continue
  fi

  # Parse the key-value pair
  key=$(echo "$line" | cut -d '=' -f 1)
  value=$(echo "$line" | cut -d '=' -f 2)

  echo ""
  echo "Creating $ENVIRONMENT_NAME/variables/$key..."
  gh api \
    --method POST \
    -H "Accept: application/vnd.github+json" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "/repositories/$REPOSITORY_ID/environments/$ENVIRONMENT_NAME/variables" \
    -f "name=$key" \
    -f "value=$value"

  # echo "Deleting $ENVIRONMENT_NAME/secrets/$key..."
  # gh api \
  #   --method DELETE \
  #   -H "Accept: application/vnd.github+json" \
  #   -H "X-GitHub-Api-Version: 2022-11-28" \
  #   "/repositories/$REPOSITORY_ID/environments/$ENVIRONMENT_NAME/secrets/$key"
done
```
