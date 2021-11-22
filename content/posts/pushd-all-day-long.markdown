---
date: 2013-03-02
layout: post
title: pushd and popd forever
categories: ["snippets"]
tags: [command-line-foo, bash-profile]
aliases: [pushd_all_day_long]
---

Becoming tired of typing paths repeatedly in the terminal, I realized that I should be using [pushd and popd](http://en.wikipedia.org/wiki/Pushd_and_popd) to be navigating directory structures.  For those uninitiated, `pushd` changes your current directory in a similar fashion to `cd` but additionally adds the former directory to a stack.  You can later return to the former directory by executing `popd`, popping it from the directory history.  Unfortunately, the commands `pushd` and `popd` both require at least twice as many characters to type as `cd` and additionally come with the overhead of having to learnt o use a new command instead of something that is nearly instinctual.  Then it came to me: `pushd` all the time.

Overriding `cd` with a muted `pushd` operates exactly like the standard `cd` command, with the added benefity that the path history is saved.  Furthermore, adding an alias of `p` to `popd` allows the previous directory to be popped with minimal effort.

Additionally, when exploring the idea, I came across [this StackExchange post](http://unix.stackexchange.com/questions/4290/aliasing-cd-to-pushd-is-it-a-good-idea) illustrating a `back` function, allowing you to switch back and forth between your current and previous directory with removing either from the stack.  In the end, this is what I put in my [bash profile](https://raw.github.com/alukach/.mySetup/master/.bash_profile):

```bash linenos
# CD is now silent pushd
cd()
{
  if [ $# -eq 0 ]; then
    DIR="${HOME}"
  else
    DIR="$1"
  fi

  builtin pushd "${DIR}" > /dev/null
}

# Take you back without popd
back()
{
  builtin pushd > /dev/null
  dirs
}

alias p='popd'
alias b='back'
```
