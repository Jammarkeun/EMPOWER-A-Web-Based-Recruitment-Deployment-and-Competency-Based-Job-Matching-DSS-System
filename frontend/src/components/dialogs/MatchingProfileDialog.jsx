import * as React from 'react'
import { Loader2, Plus, Trash2, X } from 'lucide-react'
import { getCached, put } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input, NumberInput } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field, FormGrid } from '@/components/ui/form'
import { latestBirthDate } from '@/lib/validation'

const SCORED = ['sex', 'birth_date', 'height_cm', 'distance_km', 'availability_date', 'communication_rating', 'reliability_rating']

function fromApplicant(applicant) {
  return {
    details: Object.fromEntries(SCORED.map((key) => [key, applicant[key] ?? ''])),
    educations: (applicant.educations ?? []).map((e) => ({
      education_level: e.education_level ?? '',
      school_name: e.school_name ?? '',
      course_program: e.course_program ?? '',
      graduation_year: e.graduation_year ?? '',
    })),
    experiences: (applicant.experiences ?? []).map((e) => ({
      company_name: e.company_name ?? '',
      position_title: e.position_title ?? '',
      months_experience: e.months_experience ?? '',
    })),
    skills: (applicant.skills ?? []).map((s) => s.skill_name),
    certifications: (applicant.certifications ?? []).map((c) => ({
      certification_name: c.certification_name ?? '',
      issuer: c.issuer ?? '',
      issued_at: c.issued_at?.slice(0, 10) ?? '',
      expires_at: c.expires_at?.slice(0, 10) ?? '',
    })),
  }
}

/**
 * Everything about an applicant that the matching engine scores.
 *
 * Education, work history, skills, and certificates come from the resume and
 * documents the applicant submitted; height, distance, availability, and the
 * two interview ratings from screening. Until this form existed none of the
 * first four could be recorded at all, so every applicant scored zero on them.
 *
 * Skill and certificate names are offered from the words already in use on
 * both sides - in applicants' profiles and in clients' criteria - because the
 * engine matches names, and "Computer Literate" will not match a requirement
 * for "Computer Literacy".
 */
export default function MatchingProfileDialog({ open, onOpenChange, applicant, onSaved }) {
  const toast = useToast()
  const [form, setForm] = React.useState(() => fromApplicant(applicant))
  const [vocabulary, setVocabulary] = React.useState({ skills: [], certifications: [], education_levels: [] })
  const [skillDraft, setSkillDraft] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    if (!open) return
    setForm(fromApplicant(applicant))
    setErrors({})
    setSkillDraft('')
    getCached('/qualification-vocabulary', undefined, { ttl: 60_000 })
      .then((response) => setVocabulary(response.data))
      .catch(() => {})
  }, [open, applicant])

  const setDetail = (key) => (event) =>
    setForm((f) => ({ ...f, details: { ...f.details, [key]: event.target.value } }))

  function setRow(list, index, key, value) {
    setForm((f) => ({ ...f, [list]: f[list].map((row, i) => (i === index ? { ...row, [key]: value } : row)) }))
  }

  function addRow(list, blank) {
    setForm((f) => ({ ...f, [list]: [...f[list], blank] }))
  }

  function removeRow(list, index) {
    setForm((f) => ({ ...f, [list]: f[list].filter((_, i) => i !== index) }))
  }

  function addSkill() {
    const skill = skillDraft.trim()
    if (!skill) return
    if (form.skills.some((s) => s.toLowerCase().replace(/\s+/g, ' ') === skill.toLowerCase().replace(/\s+/g, ' '))) {
      setSkillDraft('')
      return
    }
    setForm((f) => ({ ...f, skills: [...f.skills, skill] }))
    setSkillDraft('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSaving(true)
    setErrors({})

    const original = fromApplicant(applicant).details
    const changedDetails = Object.fromEntries(
      SCORED.filter((key) => String(form.details[key] ?? '') !== String(original[key] ?? '')).map((key) => [
        key,
        form.details[key] === '' ? null : form.details[key],
      ])
    )

    try {
      if (Object.keys(changedDetails).length) {
        await put(`/applicants/${applicant.id}`, changedDetails)
      }

      await put(`/applicants/${applicant.id}/qualifications`, {
        educations: form.educations.map((e) => ({ ...e, graduation_year: e.graduation_year || null, course_program: e.course_program || null })),
        experiences: form.experiences.map((e) => ({ ...e, months_experience: e.months_experience === '' ? null : Number(e.months_experience) })),
        skills: form.skills,
        certifications: form.certifications.map((c) => ({
          ...c,
          issuer: c.issuer || null,
          issued_at: c.issued_at || null,
          expires_at: c.expires_at || null,
        })),
      })

      toast.success('Matching profile saved', 'Run the evaluation again on requests that include this applicant.')
      onOpenChange(false)
      onSaved()
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
        toast.error('Some entries need fixing', error.message)
      } else {
        toast.error('Could not save the profile', error.message)
      }
    } finally {
      setSaving(false)
    }
  }

  const err = (key) => errors[key]?.[0]
  const levels = vocabulary.education_levels?.length
    ? vocabulary.education_levels
    : ['elementary', 'high_school', 'senior_high_school', 'vocational', 'college_undergraduate', 'college_graduate', 'postgraduate'].map(
        (value) => ({ value, label: value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) })
      )

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Matching profile</DialogTitle>
          <DialogDescription>
            What the competency evaluation compares with a request's criteria. Record it from the
            applicant's resume, documents, and screening interview.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-6" noValidate>
          <section className="space-y-3">
            <h3 className="text-sm font-semibold">Screening details</h3>
            <FormGrid className="sm:grid-cols-3">
              <Field label="Sex" htmlFor="mp_sex" error={err('sex')}>
                <Select value={form.details.sex} onChange={setDetail('sex')} placeholder="Not recorded">
                  <option value="female">Female</option>
                  <option value="male">Male</option>
                </Select>
              </Field>
              <Field label="Date of birth" htmlFor="mp_birth" error={err('birth_date')}>
                <Input type="date" value={form.details.birth_date?.slice?.(0, 10) ?? ''} onChange={setDetail('birth_date')} max={latestBirthDate()} />
              </Field>
              <Field label="Height (cm)" htmlFor="mp_height" error={err('height_cm')}>
                <NumberInput min="100" max="250" step="0.01" value={form.details.height_cm} onChange={setDetail('height_cm')} />
              </Field>
              <Field label="Distance from worksite (km)" htmlFor="mp_distance" error={err('distance_km')}>
                <NumberInput min="0" max="9999" step="0.01" value={form.details.distance_km} onChange={setDetail('distance_km')} />
              </Field>
              <Field label="Available from" htmlFor="mp_available" error={err('availability_date')}>
                <Input
                  type="date"
                  value={form.details.availability_date?.slice?.(0, 10) ?? ''}
                  onChange={setDetail('availability_date')}
                />
              </Field>
              <div />
              <Field label="Communication (interview, 0–5)" htmlFor="mp_comm" error={err('communication_rating')}>
                <NumberInput min="0" max="5" step="0.5" value={form.details.communication_rating} onChange={setDetail('communication_rating')} />
              </Field>
              <Field label="Reliability (interview, 0–5)" htmlFor="mp_rel" error={err('reliability_rating')}>
                <NumberInput min="0" max="5" step="0.5" value={form.details.reliability_rating} onChange={setDetail('reliability_rating')} />
              </Field>
            </FormGrid>
          </section>

          <section className="space-y-3">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold">Education</h3>
              <Button type="button" variant="outline" size="sm" onClick={() => addRow('educations', { education_level: '', school_name: '', course_program: '', graduation_year: '' })}>
                <Plus className="h-3.5 w-3.5" />
                Add
              </Button>
            </div>
            {form.educations.length === 0 && <p className="text-xs text-muted-foreground">None recorded.</p>}
            {form.educations.map((row, i) => (
              <div key={i} className="grid gap-2 rounded-md border p-2 sm:grid-cols-[11rem_1fr_1fr_6rem_auto]">
                <Select value={row.education_level} onChange={(e) => setRow('educations', i, 'education_level', e.target.value)} placeholder="Level" aria-label="Education level">
                  {levels.map((l) => (
                    <option key={l.value} value={l.value}>
                      {l.label}
                    </option>
                  ))}
                </Select>
                <Input placeholder="School" value={row.school_name} onChange={(e) => setRow('educations', i, 'school_name', e.target.value)} aria-label="School" />
                <Input placeholder="Course (optional)" value={row.course_program} onChange={(e) => setRow('educations', i, 'course_program', e.target.value)} aria-label="Course" />
                <NumberInput placeholder="Year" min="1950" max={new Date().getFullYear() + 6} step="1" value={row.graduation_year} onChange={(e) => setRow('educations', i, 'graduation_year', e.target.value)} aria-label="Graduation year" />
                <Button type="button" variant="ghost" size="icon" onClick={() => removeRow('educations', i)} aria-label="Remove education">
                  <Trash2 className="h-4 w-4" />
                </Button>
                {(err(`educations.${i}.education_level`) || err(`educations.${i}.school_name`)) && (
                  <p className="text-xs text-destructive sm:col-span-5">
                    {err(`educations.${i}.education_level`) ?? err(`educations.${i}.school_name`)}
                  </p>
                )}
              </div>
            ))}
          </section>

          <section className="space-y-3">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold">Work experience</h3>
              <Button type="button" variant="outline" size="sm" onClick={() => addRow('experiences', { company_name: '', position_title: '', months_experience: '' })}>
                <Plus className="h-3.5 w-3.5" />
                Add
              </Button>
            </div>
            {form.experiences.length === 0 && <p className="text-xs text-muted-foreground">None recorded.</p>}
            {form.experiences.map((row, i) => (
              <div key={i} className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_1fr_7rem_auto]">
                <Input placeholder="Employer" value={row.company_name} onChange={(e) => setRow('experiences', i, 'company_name', e.target.value)} aria-label="Employer" />
                <Input placeholder="Position" value={row.position_title} onChange={(e) => setRow('experiences', i, 'position_title', e.target.value)} aria-label="Position" />
                <NumberInput placeholder="Months" min="0" max="600" step="1" value={row.months_experience} onChange={(e) => setRow('experiences', i, 'months_experience', e.target.value)} aria-label="Months" />
                <Button type="button" variant="ghost" size="icon" onClick={() => removeRow('experiences', i)} aria-label="Remove experience">
                  <Trash2 className="h-4 w-4" />
                </Button>
                {(err(`experiences.${i}.company_name`) || err(`experiences.${i}.position_title`) || err(`experiences.${i}.months_experience`)) && (
                  <p className="text-xs text-destructive sm:col-span-4">
                    {err(`experiences.${i}.company_name`) ?? err(`experiences.${i}.position_title`) ?? err(`experiences.${i}.months_experience`)}
                  </p>
                )}
              </div>
            ))}
          </section>

          <section className="space-y-3">
            <h3 className="text-sm font-semibold">Skills</h3>
            {form.skills.length > 0 && (
              <ul className="flex flex-wrap gap-1.5">
                {form.skills.map((skill) => (
                  <li key={skill} className="inline-flex items-center gap-1 rounded-full border bg-secondary py-0.5 pl-2.5 pr-1 text-xs">
                    {skill}
                    <button
                      type="button"
                      onClick={() => setForm((f) => ({ ...f, skills: f.skills.filter((s) => s !== skill) }))}
                      className="rounded p-0.5 text-muted-foreground hover:text-destructive"
                      aria-label={`Remove ${skill}`}
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <div className="flex gap-1.5">
              <Input
                list="skill-vocabulary"
                value={skillDraft}
                onChange={(e) => setSkillDraft(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    addSkill()
                  }
                }}
                placeholder="e.g. Machine Operation"
                className="h-8 text-sm"
                aria-label="Add a skill"
              />
              <datalist id="skill-vocabulary">
                {vocabulary.skills.map((s) => (
                  <option key={s} value={s} />
                ))}
              </datalist>
              <Button type="button" variant="outline" size="sm" className="h-8" onClick={addSkill}>
                <Plus className="h-3.5 w-3.5" />
                Add
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              Suggestions are the names already used by applicants and in clients' criteria — pick one where
              it fits, so the requirement and the skill are matched.
            </p>
          </section>

          <section className="space-y-3">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold">Certifications</h3>
              <Button type="button" variant="outline" size="sm" onClick={() => addRow('certifications', { certification_name: '', issuer: '', issued_at: '', expires_at: '' })}>
                <Plus className="h-3.5 w-3.5" />
                Add
              </Button>
            </div>
            {form.certifications.length === 0 && <p className="text-xs text-muted-foreground">None recorded.</p>}
            <datalist id="cert-vocabulary">
              {vocabulary.certifications.map((c) => (
                <option key={c} value={c} />
              ))}
            </datalist>
            {form.certifications.map((row, i) => (
              <div key={i} className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_9rem_9rem_9rem_auto]">
                <Input list="cert-vocabulary" placeholder="Certificate" value={row.certification_name} onChange={(e) => setRow('certifications', i, 'certification_name', e.target.value)} aria-label="Certificate" />
                <Input placeholder="Issuer" value={row.issuer} onChange={(e) => setRow('certifications', i, 'issuer', e.target.value)} aria-label="Issuer" />
                <Input type="date" value={row.issued_at} onChange={(e) => setRow('certifications', i, 'issued_at', e.target.value)} aria-label="Issued" title="Issued" />
                <Input type="date" value={row.expires_at} min={row.issued_at || undefined} onChange={(e) => setRow('certifications', i, 'expires_at', e.target.value)} aria-label="Expires" title="Expires (an expired certificate does not count)" />
                <Button type="button" variant="ghost" size="icon" onClick={() => removeRow('certifications', i)} aria-label="Remove certificate">
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </section>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Save profile
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
