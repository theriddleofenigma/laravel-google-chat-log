# Security Policy

## Supported Versions

Security fixes are provided for the latest major release of the package running
on a supported Laravel version (currently Laravel 11, 12 and 13).

## Reporting a Vulnerability

If you discover a security vulnerability, please report it privately rather than
opening a public issue.

- Preferred: use GitHub's
  [private vulnerability reporting](https://github.com/theriddleofenigma/laravel-google-chat-log/security/advisories/new).
- Alternatively, email **kumarwindows11@gmail.com** with the details.

Please include:

- A description of the vulnerability and its impact.
- Steps to reproduce, or a proof of concept.
- Any suggested remediation, if you have one.

You can expect an acknowledgement of your report, and we will keep you informed
as the issue is investigated and resolved. Please give us a reasonable amount of
time to address the issue before any public disclosure.

## A note on webhook urls

Google Chat incoming webhook urls contain a `key` and `token` and are
credentials in their own right &mdash; anyone holding one can post to your
space. Keep them in environment variables, never commit them, and redact them
from logs, issue reports and reproductions before sharing.
