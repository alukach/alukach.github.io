---
title: "Concurrent Python Example Script"
date: 2021-02-19T13:42:21-07:00
draft: false
tags: [python]
---

Below is a very simple example of a script that I write and re-write more often than I would like to admit. It reads input data from a CSV and processes each row concurrently. A progress bar provides updates.  Honestly, it's pretty much just the `concurrent.futures` [`ThreadPoolExecutor` example](https://docs.python.org/3/library/concurrent.futures.html#threadpoolexecutor-example) plus a [progress bar](https://github.com/tqdm/tqdm).

{{< gist alukach 47f253b13cde2c7939c4f8061f3a28dd >}}
