import { describe, expect, it } from 'vitest'
import { queryParams } from './api'

describe('queryParams', () => {
  it('sends booleans as 1 and 0, which Laravel accepts, and leaves the rest alone', () => {
    // Laravel's boolean rule refuses the text "true" a query string would carry.
    expect(queryParams({ only_open: true, include_withdrawn: false, page: 2, status: 'open', search: undefined })).toEqual({
      only_open: 1,
      include_withdrawn: 0,
      page: 2,
      status: 'open',
      search: undefined,
    })
    expect(queryParams(undefined)).toBeUndefined()
  })
})
