---
name: Bug report
about: Create a report to help us improve
title: ''
labels: bug
assignees: ''
---

**Describe the bug**
A clear and concise description of what the bug is.

**To Reproduce**
A minimal code sample that reproduces the issue, e.g. your `google-chat`
channel configuration and the log call that triggers the problem:

```php
// config/logging.php channel config (redact the webhook url!)

// the log call
Log::channel('google-chat')->error('...');
```

> :warning: **Never paste your webhook url.** It contains a `key` and `token`
> that let anyone post to your space &mdash; redact them as `key=...&token=...`.

**Expected behavior**
A clear and concise description of what you expected to happen.

**What was sent / received**
If relevant, the message that appeared in Google Chat (a screenshot is fine),
or the HTTP response returned by the Chat API.

**Environment**
 - Package version: [e.g. 3.0.0]
 - Laravel version: [e.g. 13.0.0]
 - PHP version: [e.g. 8.4]

**Additional context**
Add any other context about the problem here (stack traces, related issues, etc.).
