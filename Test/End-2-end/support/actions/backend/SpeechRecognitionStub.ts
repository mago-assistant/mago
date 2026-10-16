/*
 * Copyright © Mago Assistant
 */

import {type Page} from '@playwright/test';

type RecognitionLog = {
  started: number;
  stopped: number;
  aborted: number;
  lang: string;
};

/**
 * Headless Chromium has no microphone and no speech service, so the browser's SpeechRecognition is
 * replaced before the admin page loads. The stub records what the panel asks of it and lets a spec
 * play the recogniser's side: words heard, the end of a recording, an error.
 */
export default class SpeechRecognitionStub {
  async install(page: Page) {
    await page.addInitScript(() => {
      type Handler = ((event: unknown) => void) | null;
      const win = window as unknown as Record<string, unknown>;
      const log = {started: 0, stopped: 0, aborted: 0, lang: ''};

      class StubRecognition {
        lang = '';
        interimResults = false;
        continuous = false;
        onresult: Handler = null;
        onerror: Handler = null;
        onend: Handler = null;

        start() {
          log.started++;
          log.lang = this.lang;
          win.magoRecognition = this;
        }

        stop() {
          log.stopped++;
          this.end();
        }

        abort() {
          log.aborted++;
          this.end();
        }

        hear(transcripts: string[]) {
          const results = transcripts.map((transcript) => [{transcript}]);
          this.onresult?.({results});
        }

        fail(error: string) {
          this.onerror?.({error});
          this.end();
        }

        end() {
          if (win.magoRecognition !== this) return;
          win.magoRecognition = null;
          this.onend?.({});
        }
      }

      win.magoRecognitionLog = log;
      win.webkitSpeechRecognition = StubRecognition;
      win.SpeechRecognition = StubRecognition;
    });
  }

  async removeFromBrowser(page: Page) {
    await page.addInitScript(() => {
      const win = window as unknown as Record<string, unknown>;
      delete win.SpeechRecognition;
      delete win.webkitSpeechRecognition;
    });
  }

  async rememberConsent(page: Page) {
    await page.addInitScript(() => window.localStorage.setItem('mago-voice-consent', '1'));
  }

  /** The recogniser reports everything heard so far in this recording, as the real one does. */
  async hear(page: Page, ...transcripts: string[]) {
    await page.evaluate((heard) => {
      const recognition = (window as unknown as {magoRecognition: {hear: (t: string[]) => void}}).magoRecognition;
      recognition.hear(heard);
    }, transcripts);
  }

  /** The recogniser stops by itself, as it does at the first pause. */
  async pause(page: Page) {
    await page.evaluate(() => {
      (window as unknown as {magoRecognition: {end: () => void}}).magoRecognition.end();
    });
  }

  async fail(page: Page, error: string) {
    await page.evaluate((code) => {
      (window as unknown as {magoRecognition: {fail: (e: string) => void}}).magoRecognition.fail(code);
    }, error);
  }

  async log(page: Page): Promise<RecognitionLog> {
    return page.evaluate(() => (window as unknown as {magoRecognitionLog: RecognitionLog}).magoRecognitionLog);
  }
}
