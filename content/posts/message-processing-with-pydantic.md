---
date: 2022-08-05
layout: post
title: Type-based message processing with Pydantic
categories: ["posts"]
tags: [python, pydantic]
---

When building systems to process messages, it's not unlikely to find yourself in a situation where you need to process a number of inputted heterogeneous messages (i.e. messages of varying shapes/types). For example, consider a situation where you are processing messages from an SQS queue via a Lambda function. This post attempts to highlight how this can be achieved in a clean and elegant manner by utilizing [Pydantic](https://pydantic-docs.helpmanual.io/), Python's [typing](https://docs.python.org/3/library/typing.html) system, and some helpers from the Python standard library.

## Categorizing messages of unknown type

The first thing you likely need to do is identify the type of an inputted message by its properties. We can use Pydantic to model the types of messages we expect to have coming into our system. We can then utilize [Pydantic's `parse_obj_as`](https://pydantic-docs.helpmanual.io/usage/models/#parsing-data-into-a-specified-type) function to cast these messages as their

In the following example, we are able to distinguish between messages based on the _attributes_ that they contain:

```py
import pydantic
import typing

class Email(pydantic.BaseModel):
    to: typing.List[str]
    subject: str
    message: str

class Sms(pydantic.BaseModel):
    to: str
    message: str

# Create a type demonstrating all of the expected messages
SupportedMessages = typing.Union[Email, Sms]

# Pass in our new type with a list of uncategorized messages
messages = pydantic.parse_obj_as(
    typing.List[SupportedMessages],
    [
        {
            "to": ['bill@gmail.com', 'alice@outlook.com'],
            "subject": "BBQ Emergency",
            "message": "Need more ketchup!"
        },
        {
            "to": "911",
            "message": "Burnt finger at BBQ :("
        }
    ]
)

# They have now been cast to their appropriate types
print(messages)
#> [Email(to=['bill@gmail.com', 'alice@outlook.com'], subject='BBQ Emergency', message='Need more ketchup!'), Sms(to='911', message='Burnt finger at BBQ :(')]
```

Other times, messages are differentiated based on the _value_ of a particular attribute. The same pattern applies:

<details>

<summary>Example of value-based differentiation</summary>

```py
import pydantic
import typing

class Email(pydantic.BaseModel):
    type: typing.Literal["email"]
    to: str
    message: str

class Sms(pydantic.BaseModel):
    type: typing.Literal["sms"]
    to: str
    message: str

# Create a type demonstrating all of the expected messages
SupportedMessages = typing.Union[Email, Sms]

# Pass in our new type with a list of uncategorized messages
messages = pydantic.parse_obj_as(
    typing.List[SupportedMessages],
    [
        {
            "type": "email",
            "to": "paul@outlook.com",
            "message": "Head's up, Ringo has a new idea"
        },
        {
            "type": "sms",
            "to": "867-5309",
            "message": "New phone, who dis?"
        }
    ]
)

# They have now been cast to their appropriate types
print(messages)
#> [Email(type='email', to='paul@outlook.com', message="Head's up, Ringo has a new idea"), Sms(type='sms', to='867-5309', message='New phone, who dis?')]
```

</details>

### Edge Cases

#### Unknown types

In the event that a message does not fit any model, a `ValidationError` will be thrown:

<details>

<summary>Example of an unexpected message</summary>

```py
import pydantic
import typing

class Email(pydantic.BaseModel):
    to: typing.List[str]
    subject: str
    message: str

pydantic.parse_obj_as(Email, {"foo": "bar"})
#> Traceback (most recent call last):
#    File "<stdin>", line 1, in <module>
#    File "pydantic/tools.py", line 38, in pydantic.tools.parse_obj_as
#    File "pydantic/main.py", line 341, in pydantic.main.BaseModel.__init__
#  pydantic.error_wrappers.ValidationError: 3 validation errors for ParsingModel[Email]
#  __root__ -> to
#    field required (type=value_error.missing)
#  __root__ -> subject
#    field required (type=value_error.missing)
#  __root__ -> message
#    field required (type=value_error.missing)
```

</details>

#### Similar types

You can find yourself in challenging situations when one type is a subset of another:

<details>

<summary>Example of a situation where you can differentiate between similar message types</summary>

```py
import pydantic
import typing

class Person(pydantic.BaseModel):
    name: str

class Pet(pydantic.BaseModel):
    name: str
    breed: str

print(pydantic.parse_obj_as(typing.Union[Person, Pet], {"name": "Bob"}))
#> Person(name='Bob')
pydantic.parse_obj_as(typing.Union[Person, Pet], {"name": "Fido", "breed": "poodle"})
#> Person(name='Fido')
```

</details>

By default, Pydantic permits extra attributes on models. By specifying that extra attributes are forbidden via the [`extra` option](https://pydantic-docs.helpmanual.io/usage/model_config/#options),

<details>

<summary>Example of using `extra` to help differentiate between similar message types</summary>

```py
class Person(pydantic.BaseModel, extra=pydantic.Extra.forbid):
    name: str

class Pet(pydantic.BaseModel, extra=pydantic.Extra.forbid):
    name: str
    breed: str

pydantic.parse_obj_as(typing.Union[Person, Pet], {"name": "Bob"})
#> Person(name='Bob')
pydantic.parse_obj_as(typing.Union[Person, Pet], {"name": "Fido", "breed": "poodle"})
#> Pet(name='Fido', breed='poodle')
```

</details>

## Processing Messages

Now that we have our messages categorized, it's likely that you'll want to process each message according to its type. We could write a long `if isinstance(msg, TypeA): ... elif isinstance(msg, TypeB): ...`, but that's no fun. Instead, we can reach for Python's `functools` module, which has a convenient [`singledispatch` decorator](https://docs.python.org/3/library/functools.html#functools.singledispatch).

For those of us who aren't function programming wizards (ie myself), here are some helpful definitions from [Python's glossary](https://docs.python.org/3/glossary.html):

<blockquote>
<dl>

<dt><a href="https://docs.python.org/3/glossary.html#term-single-dispatch">single dispatch</a></dt>

<dd>A form of generic function dispatch where the implementation is chosen based on the type of a single argument.</dd>

<dt><a href="https://docs.python.org/3/glossary.html#term-generic-function">generic function</a></dt>

<dd>A function composed of multiple functions implementing the same operation for different types. Which implementation should be used during a call is determined by the dispatch algorithm.</dd>

</dl>
</blockquote>

Let's take a look at how that could work:

```py
import functools
import pydantic
import typing

class Foo(pydantic.BaseModel):
    type: typing.Literal["foo"]

class Bar(pydantic.BaseModel):
    type: typing.Literal["foo"]


@functools.singledispatch
def send(msg):
    # this is the default sender, which should only ever be called if a message
    # comes in with a type for which we haven't registered a handler. in this
    # situation, we may want to throw an error to signal that we don't know how
    # to handle this message; alternatively we may want to have a default handler
    # that applies to any types without explicitly registered handlers.
    ...

@send.register
def handle_foo(msg: Foo):
    ...

@send.register
def handle_bar(msg: Bar):
    ...
```

<details>

<summary>Example of applying this pattern to our previous Email/SMS types</summary>

```py
import functools
import pydantic
import typing


class Email(pydantic.BaseModel):
    to: typing.List[str]
    subject: str
    message: str


class Sms(pydantic.BaseModel):
    to: str
    message: str


@functools.singledispatch
def send(msg):
    # this is the default sender, which should only ever be called if a message
    # comes in with a type for which we haven't registered a handler. in this
    # situation, we may want to throw an error to signal that we don't know how
    # to handle this message; alternatively we may want to have a default handler
    # that applies to any types without explicitly registered handlers.
    raise Exception(f"Unexpected message type ({type(msg)=}, {msg})")

@send.register
def send_email(msg: Email):
    print(f"Sending email to {' and '.join(msg.to)}")

@send.register
def send_sms(msg: Sms):
    print(f"Sending SMS to {msg.to}")

def handle_message(message: typing.Dict[str, typing.Any]):
    parsed_message = pydantic.parse_obj_as(typing.Union[Email, Sms], message)
    send(parsed_message)

handle_message({
    "to": ['bill@gmail.com', 'alice@outlook.com'],
    "subject": "BBQ Emergency",
    "message": "Need more ketchup!"
})
#> Sending email to bill@gmail.com and alice@outlook.com
handle_message({
    "to": "911",
    "message": "Burnt finger at BBQ :("
})
#> Sending SMS to 911
```

</details>

## Testing

This type-based message handling is neat and all, but can we test this? I found it to be a bit challenging to integrate [mocking](https://docs.python.org/3/library/unittest.mock.html) with the `@functools.singledispatch`, but coming up with a simple context manager to conveniently swap out registered type handlers with mocks:

```py
import contextlib
import typing
import unittest.mock

@contextlib.contextmanager
def override_registry(
    dispatch_callable: "_SingleDispatchCallable[Any]",
    cls: typing.Type,
    mock: unittest.mock.Mock,
):
    """
    Helper to override a singledispatch function with a mock for testing.
    """
    original = dispatch_callable.registry[cls]
    dispatch_callable.register(cls, mock)
    try:
        yield mock
    finally:
        dispatch_callable.register(cls, original)
```

<details>

<summary>Example of a full set of tests to validate that routing logic is appropriately configured</summary>

```py
import functools
import pydantic
import typing
import contextlib
import unittest.mock


class Email(pydantic.BaseModel):
    to: typing.List[str]
    subject: str
    message: str


class Sms(pydantic.BaseModel):
    to: str
    message: str


@functools.singledispatch
def send(msg):
    # this is the default sender, which should only ever be called if a message
    # comes in with a type for which we haven't registered a handler. in this
    # situation, we may want to throw an error to signal that we don't know how
    # to handle this message; alternatively we may want to have a default handler
    # that applies to any types without explicitely registered handlers.
    raise Exception(f"Unexpected message type ({type(msg)=}, {msg})")

@send.register
def send_email(msg: Email):
    print(f"Sending email to {' and '.join(msg.to)}")

@send.register
def send_sms(msg: Sms):
    print(f"Sending SMS to {msg.to}")

def handle_message(message: typing.Dict[str, typing.Any]):
    parsed_message = pydantic.parse_obj_as(typing.Union[Email, Sms], message)
    send(parsed_message)

@contextlib.contextmanager
def override_registry(
    dispatch_callable: "_SingleDispatchCallable[Any]",
    cls: typing.Type,
    mock: unittest.mock.Mock,
):
    """
    Helper to override a singledispatch function with a mock for testing.
    """
    original = dispatch_callable.registry[cls]
    dispatch_callable.register(cls, mock)
    try:
        yield mock
    finally:
        dispatch_callable.register(cls, original)

def test_handling_email():
    """
    Ensure that the system properly handles Email messages.
    """
    with override_registry(
        send, Email, unittest.mock.MagicMock()
    ) as called_mock, override_registry(
        send, Sms, unittest.mock.MagicMock()
    ) as not_called_mock:
        output = handle_message({
            "to": ['bill@gmail.com', 'alice@outlook.com'],
            "subject": "BBQ Emergency",
            "message": "Need more ketchup!"
        })

    assert called_mock.call_count == 1
    assert not not_called_mock.call_count

def test_handling_sms():
    """
    Ensure that the system properly handles SMS messages.
    """
    with override_registry(
        send, Sms, unittest.mock.MagicMock()
    ) as called_mock, override_registry(
        send, Email, unittest.mock.MagicMock()
    ) as not_called_mock:
        output = handle_message({
            "to": "911",
            "message": "Burnt finger at BBQ :("
        })

    assert called_mock.call_count == 1
    assert not not_called_mock.call_count

test_handling_email()
test_handling_sms()
```

<details>

---

This pattern is all a bit new to me and fresh in my mind.  Hoping it can prove useful to others.  I'm very open to hearing concerns or suggestions for improvement from others if anyone has them.
