---
date: 2018-04-30
layout: post
title: Using CloudFormation's Fn::Sub with Bash parameter substitution
category: posts
tags: [quick-hint, cloudformation, aws]
---

Let's say that you need to inject a large bash script into a CloudFormation `AWS::EC2::Instance` Resource's `UserData` property. CloudFormation makes this easy with the [`Fn::Base64` intrinsic function](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/intrinsic-function-reference-base64.html):

```yaml
AWSTemplateFormatVersion: '2010-09-09'

Resources:
  VPNServerInstance:
    Type: AWS::EC2::Instance
    Properties:
      ImageId: ami-efd0428f
      InstanceType: m3.medium
      UserData:
        Fn::Base64: |
          #!/bin/sh
          echo "Hello world"
```

In your bash script, you may even want to reference a parameter created elsewhere in the CloudFormation template.  This is no problem with Cloudformation's [`Fn::Sub` instrinsic function](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/intrinsic-function-reference-sub.html):

```yaml
AWSTemplateFormatVersion: '2010-09-09'

Parameters:
  Username:
    Description: Username
    Type: String
    MinLength: '1'
    MaxLength: '255'
    AllowedPattern: '[a-zA-Z][a-zA-Z0-9]*'
    ConstraintDescription: must begin with a letter and contain only alphanumeric
      characters.

Resources:
  VPNServerInstance:
    Type: AWS::EC2::Instance
    Properties:
      ImageId: ami-efd0428f
      InstanceType: m3.medium
      UserData:
        Fn::Base64: !Sub |
          #!/bin/sh
          echo "Hello ${Username}"
```

The downside of the `Fn::Sub` function is that it does not play nice with bash' [parameter substitution](https://www.tldp.org/LDP/abs/html/parameter-substitution.html) expressions:

```yaml
AWSTemplateFormatVersion: '2010-09-09'

Parameters:
  Username:
    Description: Username
    Type: String
    MinLength: '1'
    MaxLength: '255'
    AllowedPattern: '[a-zA-Z][a-zA-Z0-9]*'
    ConstraintDescription: must begin with a letter and contain only alphanumeric
      characters.

Resources:
  VPNServerInstance:
    Type: AWS::EC2::Instance
    Properties:
      ImageId: ami-efd0428f
      InstanceType: m3.medium
      UserData:
        Fn::Base64: !Sub |
          #!/bin/sh
          echo "Hello ${Username}"
          FOO=${FOO:-'bar'}
```

The above template fails validation:

```sh
$ aws cloudformation validate-template --template-body file://test.yaml

An error occurred (ValidationError) when calling the ValidateTemplate operation: Template error: variable names in Fn::Sub syntax must contain only alphanumeric characters, underscores, periods, and colons
```

**The work-around is to rely on another intrinsic function: [`Fn::Join`](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/intrinsic-function-reference-join.html):**

```yaml
AWSTemplateFormatVersion: '2010-09-09'

Parameters:
  Username:
    Description: Username
    Type: String
    MinLength: '1'
    MaxLength: '255'
    AllowedPattern: '[a-zA-Z][a-zA-Z0-9]*'
    ConstraintDescription: must begin with a letter and contain only alphanumeric
      characters.

Resources:
  VPNServerInstance:
    Type: AWS::EC2::Instance
    Properties:
      ImageId: ami-efd0428f
      InstanceType: m3.medium
      UserData:
        Fn::Base64: !Join
          - '\n'
          - - !Sub |
              #!/bin/sh
              echo "Hello ${Username}"
            - |
              FOO=${FOO:-'bar'}
```

This allows you to mix CloudFormation substitutions with Bash parameter substititions.

---

### Bonus

While we're talking about CloudFormation, another good trick comes from [cloudonaut.io](https://cloudonaut.io) regarding using a [Optional Parameter in CloudFormation](https://cloudonaut.io/optional-parameter-in-cloudformation/).
  
```yaml
Parameters:
  KeyName:
    Description: (Optional) Select an ssh key pair if you will need SSH access to the machine
    Type: String

Conditions:
  HasKeyName:
    Fn::Not:
    - Fn::Equals:
      - ''
      - Ref: KeyName

Resources:
  VPNServerInstance:
    Type: AWS::EC2::Instance
    Properties:
      ImageId: ami-efd0428f
      InstanceType: m3.medium
      KeyName:
        Fn::If:
          - HasKeyName
          - !Ref KeyName
          - !Ref AWS::NoValue
```

Note that the `KeyName` has `Type: String`.  While `Type: AWS::EC2::KeyPair::KeyName` would likely be a better user experience as it would render a dropdown of all keys, it [does not allow for empty values:](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/parameters-section-structure.html#w2ab2c17c15c17c21b5)

> ... if you use the `AWS::EC2::KeyPair::KeyName` parameter type, AWS CloudFormation validates the input value against users' existing key pair names before it creates any resources, such as Amazon EC2 instances.
