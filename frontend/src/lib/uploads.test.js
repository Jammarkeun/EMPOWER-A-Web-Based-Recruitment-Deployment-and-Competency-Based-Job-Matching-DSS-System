import { describe, expect, it } from 'vitest'
import { DOCUMENT_ACCEPT, READABLE_ACCEPT, isReadable } from './uploads'

/*
 * The lists mirror App\Services\DocumentUploadPolicy, which checks every file
 * again by its content; DocumentStorageSecurityTest covers that side.
 */
describe('uploads', () => {
  it('offers everyday documents but nothing a computer would run', () => {
    const offered = DOCUMENT_ACCEPT.split(',')

    expect(offered).toEqual(expect.arrayContaining(['.pdf', '.jpg', '.heic', '.docx', '.xlsx', '.pptx', '.txt']))
    for (const refused of ['.html', '.svg', '.php', '.exe', '.zip', '.docm']) {
      expect(offered).not.toContain(refused)
    }
  })

  it('offers document reading only for PDFs and photos', () => {
    expect(READABLE_ACCEPT).toBe('.pdf,.jpg,.jpeg,.jpe,.jfif,.png,.webp')
    expect(isReadable({ name: 'NBI Clearance.PDF' })).toBe(true)
    expect(isReadable({ name: 'old-camera.JPE' })).toBe(true)
    expect(isReadable({ name: 'id-photo.jpeg' })).toBe(true)
    expect(isReadable({ name: 'Resume.docx' })).toBe(false)
    expect(isReadable({ name: 'scan.heic' })).toBe(false)
    expect(isReadable(null)).toBe(false)
  })
})
