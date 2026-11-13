# Security policy

Security matters to us: this Buffer class holds the bytes that xrpl-php serializes, hashes and
signs. No matter how careful we are, vulnerabilities can still slip through, and we are grateful
for every report that helps us find them.

## Supported versions

| Version | Supported |
|---|---|
| 2.x | yes |
| older than 2.0 | no |

Security fixes go into the latest 2.x release. Please check that the problem still occurs there.

## Reporting a vulnerability

Please do not report a security problem in a public issue, pull request or discussion. Report it
privately, in one of two ways:

- **GitHub:** open the [Security tab](https://github.com/AlexanderBuzz/Buffer/security) of this
  repository and choose "Report a vulnerability".
- **E-mail:** write to [info@ledger-direct.com](mailto:info@ledger-direct.com).

Please include the version of `hardcastle/buffer`, what an attacker can do, and the steps to
reproduce it.

## Responsible disclosure

We confirm receipt within 5 business days, investigate the report privately and keep you informed
while we fix it. Please do not disclose the vulnerability until a fix is published and users have
had time to upgrade. Test only against your own installations, never against other people's
systems, and refrain from anything that puts our users, the project or its maintainers at risk.

## Scope

In scope: this library as published on Packagist (`hardcastle/buffer`).

Out of scope: Node.js and its `Buffer` module, which this library follows but does not use. A
vulnerability in a dependency belongs to its maintainers; tell us as well if this library is
affected by it.

## Thank you

We appreciate every responsible report and credit you in the security advisory, unless you prefer
not to be named.
