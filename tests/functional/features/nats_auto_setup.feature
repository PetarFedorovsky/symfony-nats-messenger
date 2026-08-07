Feature: NATS Auto Setup
  Tests the auto_setup option, which makes the transport provision the stream
  and its durable pull consumer itself instead of requiring
  messenger:setup-transports. These scenarios cover the two things unit tests
  with mocked JetStream cannot prove: that a real NATS server ends up with the
  stream and consumer without the setup command ever running, and that a
  consumer removed from the server (as NATS itself does after
  inactive_threshold) is recreated on the next pull instead of leaving the
  worker polling a consumer that no longer exists.

  Background:
    Given NATS server is running

  @auto-setup
  Scenario: Auto setup provisions the stream and consumer on the first send
    Given I have a messenger transport configured with auto setup enabled
    When I send 3 messages to the transport
    Then the NATS stream should exist
    And the NATS stream should have a consumer named "client"

  # This covers the worker-restart path: messenger:consume starts a fresh process, so a new transport
  # instance provisions on its first pull. The in-process recovery path, where the SAME instance sees
  # the 503 that a deleted consumer produces and re-provisions, is covered by the unit tests
  # (testAutoSetupReprovisionsForEveryMissingResourceStatus) because Behat drives the transport through
  # a separate console process and cannot observe a single instance across the deletion.
  @auto-setup
  Scenario: A worker started after the consumer was removed re-provisions it
    Given I have a messenger transport configured with auto setup enabled
    When I send 3 messages to the transport
    And the durable consumer "client" is deleted from JetStream
    And I start a messenger consumer
    And I wait for messages to be consumed
    Then all 3 messages should be consumed
    And the NATS stream should have a consumer named "client"

  @auto-setup
  Scenario: Without auto setup the stream is not provisioned implicitly
    Given I have a messenger transport configured with auto setup disabled
    Then the NATS stream should not exist
