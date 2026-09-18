import { ref } from 'vue'

/**
 * Client-side form validation helper.
 *
 *   const { errors, validateAll, validateField, clearError, isValid } =
 *       useFormValidation(formRef, rules)
 *
 * `rules` maps a field name to an array of rule objects. Each rule object may
 * contain any of:
 *   - required: true
 *   - min / max: length bounds (strings) or value bounds (numbers)
 *   - regex: RegExp (tested against String(value).trim())
 *   - type: 'email' | 'integer' | 'number'
 *   - in: array of allowed values
 *   - message: custom message (fallbacks are used otherwise)
 *
 * Errors update live (call validateField on blur/change) and on submit
 * (call validateAll).
 */
export function useFormValidation(modelRef, rules) {
  const errors = ref({})

  function validateOne(value, ruleList) {
    const empty = value === null || value === undefined || value === ''
    for (const rule of ruleList) {
      if (rule.required === true && empty) {
        return rule.message || 'Wajib diisi.'
      }
      if (empty) return null

      if (rule.regex && !rule.regex.test(String(value).trim())) {
        return rule.message || 'Format tidak valid.'
      }
      if (rule.type === 'email') {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim())) {
          return rule.message || 'Format email tidak valid.'
        }
      }
      if (rule.type === 'integer') {
        if (!Number.isInteger(Number(value))) {
          return rule.message || 'Harus berupa angka bulat.'
        }
      }
      if (rule.type === 'number') {
        if (Number.isNaN(Number(value))) {
          return rule.message || 'Harus berupa angka.'
        }
      }
      if (typeof value === 'string') {
        if (rule.min != null && value.length < rule.min) return rule.message || `Minimal ${rule.min} karakter.`
        if (rule.max != null && value.length > rule.max) return rule.message || `Maksimal ${rule.max} karakter.`
      } else if (typeof value === 'number') {
        if (rule.min != null && value < rule.min) return rule.message || `Minimal ${rule.min}.`
        if (rule.max != null && value > rule.max) return rule.message || `Maksimal ${rule.max}.`
      }
      if (rule.in && !rule.in.includes(value)) {
        return rule.message || 'Pilih nilai yang valid.'
      }
    }
    return null
  }

  function validateAll() {
    const found = {}
    const model = modelRef.value || {}
    for (const [field, ruleList] of Object.entries(rules)) {
      const msg = validateOne(model[field], ruleList)
      if (msg) found[field] = msg
    }
    errors.value = found
    return Object.keys(found).length === 0
  }

  function validateField(field) {
    const model = modelRef.value || {}
    const ruleList = rules[field] || []
    const msg = validateOne(model[field], ruleList)
    const next = { ...errors.value }
    if (msg) next[field] = msg
    else delete next[field]
    errors.value = next
    return !msg
  }

  function clearError(field) {
    if (!field) { errors.value = {}; return }
    const next = { ...errors.value }
    delete next[field]
    errors.value = next
  }

  function isValid() {
    return Object.keys(errors.value).length === 0
  }

  return { errors, validateAll, validateField, clearError, isValid }
}

/** Shared rule sets used across views. */
export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export const studentRules = (editing) => ({
  nim: editing
    ? []
    : [
        { required: true, message: 'NIM wajib diisi.' },
        { min: 8, max: 12, regex: /^[0-9]{8,12}$/, message: 'NIM harus 8-12 digit angka tanpa spasi.' },
      ],
  name: [
    { required: true, message: 'Nama wajib diisi.' },
    { min: 3, max: 100, message: 'Nama 3-100 karakter.' },
  ],
  email: editing
    ? []
    : [
        { required: true, message: 'Email wajib diisi.' },
        { type: 'email', message: 'Format email tidak valid.' },
        { max: 255, message: 'Email maksimal 255 karakter.' },
      ],
  phone: [{ type: 'number' }, { max: 20, message: 'Nomor maksimal 20 digit.' }],
  gender: [{ in: ['male', 'female', 'other'] }],
})

export const courseRules = (editing) => ({
  code: editing
    ? []
    : [
        { required: true, message: 'Kode MK wajib diisi.' },
        { regex: /^[A-Z]{2,4}[0-9]{3}$/, message: 'Format kode [A-Z]{2,4}[0-9]{3} (contoh: IF101).' },
      ],
  name: [
    { required: true, message: 'Nama MK wajib diisi.' },
    { min: 3, max: 120, message: 'Nama 3-120 karakter.' },
  ],
  credits: [
    { required: true, message: 'SKS wajib diisi.' },
    { type: 'integer', min: 1, max: 6, message: 'SKS harus 1-6 (angka bulat).' },
  ],
  max_students: [{ type: 'integer', min: 1, message: 'Jumlah minimal 1.' }],
  status: [{ in: ['open', 'closed', 'cancelled'] }],
  semester: [{ in: ['GANJIL', 'GENAP'] }],
})

export const enrollmentRules = () => ({
  student_id: [{ required: true, message: 'Pilih mahasiswa.' }],
  course_id: [{ required: true, message: 'Pilih mata kuliah.' }],
  academic_year: [
    { required: true, message: 'Tahun akademik wajib diisi.' },
    { regex: /^\d{4}-\d{4}$/, message: 'Format tahun YYYY-YYYY (contoh 2025-2026).' },
  ],
  semester: [{ required: true, in: ['GANJIL', 'GENAP'], message: 'Semester GANJIL atau GENAP.' }],
  status: [{ required: true, in: ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'], message: 'Pilih status.' }],
  grade: [{ in: ['A','A-','B+','B','B-','C','C-','D','E','I','S','K'], message: 'Nilai tidak valid.' }],
  gpa_points: [{ type: 'number', min: 0, max: 4, message: 'IPK 0.00 - 4.00.' }],
})

/** KRS "select" tab — validates the student/course lookup fields plus academic fields. */
export const krsSelectRules = () => ({
  student_nim: [
    { required: true, message: 'Pilih mahasiswa.' },
    { regex: /^[0-9]{8,12}$/, message: 'NIM harus 8-12 digit angka.' },
  ],
  course_code: [
    { required: true, message: 'Pilih mata kuliah.' },
    { regex: /^[A-Z]{2,4}[0-9]{3}$/, message: 'Kode MK harus format [A-Z]{2,4}[0-9]{3} (contoh IF101).' },
  ],
  academic_year: [
    { required: true, message: 'Tahun akademik wajib diisi.' },
    { regex: /^\d{4}-\d{4}$/, message: 'Format tahun YYYY-YYYY (contoh 2025-2026).' },
  ],
  semester: [{ required: true, in: ['GANJIL', 'GENAP'], message: 'Semester GANJIL atau GENAP.' }],
  status: [{ required: true, in: ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'], message: 'Pilih status.' }],
  grade: [{ in: ['A','A-','B+','B','B-','C','C-','D','E','I','S','K'], message: 'Nilai tidak valid.' }],
  gpa_points: [{ type: 'number', min: 0, max: 4, message: 'IPK 0.00 - 4.00.' }],
})
