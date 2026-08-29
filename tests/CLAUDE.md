# Test conventions

`tests/TestCase.php::setUp()` explicitly flushes the Monitor buffer and purges storage —
RefreshDatabase's own migration queries get captured by the Queries recorder otherwise,
polluting every test with framework-bootstrap noise.
