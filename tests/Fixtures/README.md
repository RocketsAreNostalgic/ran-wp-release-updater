# Released runtime fixtures

`protocol3-beta3-52078f1.zip` is the complete public `v0.1.0-beta.3`
runtime from commit `52078f1f5af2b4b2538f13d5072621df8ac0d562` in this
repository. Its SHA-256 is
`de9b104e34fe6864ae797ab2b5562bbbe4177d3001e3412cbb2301a302f31189`.

Regenerate it from this repository only:

```sh
git archive --format=zip --output=tests/Fixtures/protocol3-beta3-52078f1.zip 52078f1f5af2b4b2538f13d5072621df8ac0d562 bootstrap.php runtime.php runtime-copy.json src
```

The Protocol 3/4 compatibility test verifies the archive checksum before
extraction, then verifies the released bootstrap/runtime/broker source
checksums and `runtime-copy.json` before executing the fixture.

The compatibility test covers this before-activation outcome in both orders:

- Released Protocol 3 first, then Protocol 4: the later Protocol 4
  declaration is `protocol_conflict_inactive` with zero work; attempting to
  activate the retained Protocol 3 broker returns `runtime_handoff_invalid`.
- Protocol 4 first, then released Protocol 3: the later Protocol 3 declaration
  is `protocol_conflict_inactive` with zero work; attempting to activate the
  retained Protocol 4 broker returns `runtime_handoff_invalid`.

After a runtime has activated, a later incompatible declaration is rejected
without asserting that the already-active runtime's callbacks were removed.
