import { api } from '@/lib/api'

/**
 * The digital Bio-Data, as the screens describe it.
 *
 * The fields mirror App\Support\BioDataForm on the server, which decides what
 * is asked and checks every answer. The pickers' values come from the server
 * (`options`), so only the wording lives here.
 */

/** The form's steps, in the order the PDF prints its sections. */
export const BIO_DATA_STEPS = [
  { key: 'personal', label: 'Personal information', fields: ['sex', 'civil_status', 'nationality', 'height_cm', 'weight_kg'] },
  { key: 'address', label: 'Address and contact', fields: ['provincial_address'] },
  { key: 'education', label: 'Educational background', fields: ['educations'] },
  { key: 'work', label: 'Employment record', fields: ['experiences'] },
  { key: 'review', label: 'Review and submit', fields: [] },
]

export const BIO_DATA_LABELS = {
  sex: 'Sex',
  civil_status: 'Civil status',
  nationality: 'Citizenship',
  height_cm: 'Height (cm)',
  weight_kg: 'Weight (kg)',
  provincial_address: 'Provincial address',
}

/** How each state reads, the same words on the portal and the staff screens. */
export const BIO_DATA_STATES = {
  not_started: { label: 'Not started', tone: 'muted' },
  draft: { label: 'Draft', tone: 'muted' },
  submitted: { label: 'Submitted', tone: 'info' },
  under_review: { label: 'Under review', tone: 'info' },
  verified: { label: 'Verified', tone: 'success' },
  needs_correction: { label: 'Requires correction', tone: 'warning' },
}

export const BLANK_EDUCATION = {
  education_level: '',
  school_name: '',
  course_program: '',
  graduation_year: '',
  honors: '',
}

export const BLANK_EXPERIENCE = {
  company_name: '',
  position_title: '',
  start_date: '',
  end_date: '',
  responsibilities: '',
}

/** Server values (nulls) into controlled-input values (empty strings). */
export function toFormState(form) {
  const text = (value) => (value === null || value === undefined ? '' : String(value))

  return {
    sex: text(form?.sex),
    civil_status: text(form?.civil_status),
    nationality: text(form?.nationality),
    height_cm: text(form?.height_cm),
    weight_kg: text(form?.weight_kg),
    provincial_address: text(form?.provincial_address),
    educations: (form?.educations ?? []).map((row) =>
      Object.fromEntries(Object.keys(BLANK_EDUCATION).map((key) => [key, text(row[key])]))
    ),
    experiences: (form?.experiences ?? []).map((row) =>
      Object.fromEntries(Object.keys(BLANK_EXPERIENCE).map((key) => [key, text(row[key])]))
    ),
  }
}

/** Which step a server error key belongs to, so a refused submit opens it. */
export function stepOfField(key) {
  const field = key.split('.')[0]
  const index = BIO_DATA_STEPS.findIndex((step) => step.fields.includes(field))
  return index === -1 ? 0 : index
}

export function optionLabel(options, value) {
  if (value === null || value === undefined || value === '') return null
  return options?.find((option) => option.value === value)?.label ?? value
}

/**
 * Downloads a Bio-Data PDF through the signed-in API client.
 *
 * Fetched as a blob rather than opened as a link: the request carries the
 * bearer token, and no lasting URL to the document exists anywhere.
 */
export async function downloadBioDataPdf(url, fallbackName = 'bio-data.pdf') {
  const response = await api.get(url, { responseType: 'blob' })
  const disposition = response.headers?.['content-disposition'] ?? ''
  const name = /filename="?([^";]+)"?/i.exec(disposition)?.[1] ?? fallbackName

  const href = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = href
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
}
