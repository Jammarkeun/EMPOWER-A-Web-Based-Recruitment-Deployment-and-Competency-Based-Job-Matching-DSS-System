import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

/*
 * Component and logic tests (audit CQ-07, Module M).
 *
 * They run in jsdom with the API, the signed-in account and the toasts
 * replaced by test doubles, so they check what each screen does with a given
 * response - not the server, which the PHPUnit suite covers. A test here
 * showing that a button is hidden proves nothing about who the API lets in.
 *
 *   npm test           run once (CI)
 *   npm run test:watch re-run on change
 */
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.js'],
      include: ['src/**/*.test.{js,jsx}'],
      css: false,
      restoreMocks: true,
      clearMocks: true,
    },
  })
)
