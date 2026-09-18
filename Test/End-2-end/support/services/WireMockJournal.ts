/*
 * Copyright © Mago Assistant
 */

import {type APIRequestContext} from '@playwright/test';

/* Admin API of the WireMock container standing in for the provider. WireMock journals every
   request it received, which lets a test assert on what actually crossed the wire to the
   provider instead of on what the backend claims it sent. The CI compose file publishes the
   port to the runner; the local docker run in the README does the same with -p 8080:8080. */
const ADMIN_URL = process.env.WIREMOCK_ADMIN_URL || 'http://localhost:8080';

export default class WireMockJournal {
  /**
   * Bodies of every journalled provider request matching the given WireMock bodyPatterns,
   * via POST /__admin/requests/find. Returned as raw strings on purpose: an assertion on the
   * exact wire bytes cannot be weakened by a parse step.
   */
  async findRequestBodies(request: APIRequestContext, bodyPatterns: object[]): Promise<string[]> {
    const response = await request.post(ADMIN_URL + '/__admin/requests/find', {
      data: {
        method: 'POST',
        urlPath: '/v1/chat/completions',
        bodyPatterns: bodyPatterns,
      },
    });

    const result = await response.json();

    return (result.requests ?? []).map((entry: {body: string}) => entry.body);
  }
}
