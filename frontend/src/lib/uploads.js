/**
 * Which files the document pickers offer.
 *
 * The server decides what is accepted, from each file's content
 * (App\Services\DocumentUploadPolicy); these lists only spare people from
 * choosing a file that would be refused. Web pages, scripts, programs and
 * archives are left out on purpose.
 */
export const DOCUMENT_ACCEPT = [
  '.pdf',
  '.jpg', '.jpeg', '.jpe', '.jfif', '.png', '.webp', '.gif', '.bmp', '.tif', '.tiff', '.heic', '.heif',
  '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.odt', '.ods', '.odp',
  '.rtf', '.txt', '.csv',
].join(',')

/** Document reading (OCR) understands PDFs and ordinary photos only. */
const READABLE = ['.pdf', '.jpg', '.jpeg', '.jpe', '.jfif', '.png', '.webp']

export const READABLE_ACCEPT = READABLE.join(',')

/** Whether document reading can look at this file, judged by its name. */
export function isReadable(file) {
  const name = file?.name?.toLowerCase() ?? ''
  return READABLE.some((extension) => name.endsWith(extension))
}

/**
 * What a client company's own documents may be: the same as other documents,
 * less plain text and CSV, which the server refuses for these (a "permit.pdf"
 * that is really a note would otherwise sit in the list looking like a permit).
 */
export const COMPANY_DOCUMENT_ACCEPT = DOCUMENT_ACCEPT.split(',')
  .filter((extension) => extension !== '.txt' && extension !== '.csv')
  .join(',')

/** The server's upload limit (config empower.uploads.max_size_kb). */
export const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024

/**
 * Why a chosen file would be refused, judged before it is sent, or null.
 * Only the name and size can be judged here; the server reads the content.
 */
export function companyDocumentFileProblem(file) {
  if (!file) return 'Choose the file to upload.'

  const name = file.name?.toLowerCase() ?? ''
  const allowed = COMPANY_DOCUMENT_ACCEPT.split(',').some((extension) => name.endsWith(extension))
  if (!allowed) return 'This kind of file cannot be uploaded. Use a PDF, a photo or scan, or a Word or Excel file.'

  if (file.size > MAX_DOCUMENT_BYTES) return 'That file is too large. The limit is 10 MB.'

  return null
}

/** "2.4 MB", "830 KB". */
export function formatBytes(bytes) {
  if (bytes === null || bytes === undefined) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
