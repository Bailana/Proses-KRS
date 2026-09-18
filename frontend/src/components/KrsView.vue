<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import api from '../api.js'
import { useFormValidation, studentRules, courseRules, krsSelectRules } from '../useValidation.js'
import NumericInput from './NumericInput.vue'

const stateData = ref({
  enrollments: [],
  loading: false,
  filters: { status: '', academic_year: '', semester: '' },
  page: 1, perPage: 10, total: 0, totalPages: 1,
  nextCursor: null,
  prevCursor: null,
})
const sort = ref({ field: 'id', direction: 'desc' })
const searchNim = ref('')
const searchName = ref('')
const searchCourseCode = ref('')
let searchTimeout = null
const stats = ref({ total: 0, approved: 0, submitted: 0, draft: 0, rejected: 0 })

function formatNum(n) {
  return typeof n === 'number' ? n.toLocaleString() : '0'
}
const loadingStats = ref(false)

// Counter refs — animated display
const animTotal = ref(0)
const animSubmitted = ref(0)
const animApproved = ref(0)
const animRejected = ref(0)

function animateCounter(currentRef, target, duration = 800) {
  const start = currentRef.value
  const startTime = performance.now()
  function step(now) {
    const elapsed = now - startTime
    const progress = Math.min(elapsed / duration, 1)
    const eased = 1 - Math.pow(1 - progress, 3) // ease-out cubic
    currentRef.value = Math.round(start + (target - start) * eased)
    if (progress < 1) requestAnimationFrame(step)
  }
  requestAnimationFrame(step)
}

watch(stats, (newStats) => {
  animateCounter(animTotal, newStats.total)
  animateCounter(animSubmitted, newStats.submitted)
  animateCounter(animApproved, newStats.approved)
  animateCounter(animRejected, newStats.rejected)
}, { deep: true })

const showForm = ref(false)
const editingId = ref(null)
const activeTab = ref('select')
const form = ref({})
const error = ref([])
const success = ref('')
const saving = ref(false)
const toast = ref('')
const toastType = ref('')
const deleteConfirm = ref(null)

const newStudent = ref({ nim: '', name: '', email: '', phone: '' })
const newCourse = ref({ code: '', name: '', credits: 3 })

// KRS main form validation (academic_year, semester, status, grade, gpa)
const krsMain = useFormValidation(form, krsSelectRules())
// New-student inline form
const krsStudent = useFormValidation(newStudent, studentRules(false))
// New-course inline form
const krsCourse = useFormValidation(newCourse, courseRules(false))

const searchingStudent = ref(false)
const searchingCourse = ref(false)
const studentResults = ref([])
const courseResults = ref([])

const years = ['2018-2019', '2019-2020', '2020-2021', '2021-2022', '2022-2023', '2023-2024', '2024-2025', '2025-2026', '2026-2027']
const semesters = ['GANJIL', 'GENAP']
const statuses = [
  { value: 'DRAFT', label: 'Draft', color: '#64748b', bg: '#f1f5f9' },
  { value: 'SUBMITTED', label: 'Submitted', color: '#3b82f6', bg: '#dbeafe' },
  { value: 'APPROVED', label: 'Approved', color: '#10b981', bg: '#d1fae5' },
  { value: 'REJECTED', label: 'Rejected', color: '#ef4444', bg: '#fee2e2' },
]
const statusMap = {}
statuses.forEach(s => statusMap[s.value] = s)


// Sort State
const showSortPanel = ref(false)
const advancedSorts = ref([])
const sortColumnMap = {
  nim: 'NIM', student_name: 'Nama', course_code: 'Kode MK',
  course_name: 'Nama MK', academic_year: 'Tahun', semester: 'Semester',
  status: 'Status', grade: 'Nilai', gpa_points: 'IPK', created_at: 'Dibuat',
}

// Advanced Multi-Column Filters
const filterColumns = ref([])
const advancedFilters = ref([])
const showFilterPanel = ref(false)
const filterLogic = ref('and') // 'and' or 'or'
const newFilter = ref({ column: '', operator: 'equals', value: '' })
const newFilterMin = ref('')
const newFilterMax = ref('')
const newFilterMultiValues = ref([])

const filterLabelMap = {
  'NIM': 'NIM',
  'Student Name': 'Nama Mahasiswa',
  'Course Code': 'Kode MK',
  'Course Name': 'Nama MK',
  'Academic Year': 'Tahun Ajaran',
  'Semester': 'Semester',
  'Status': 'Status',
  'Grade': 'Nilai',
  'GPA Points': 'IPK',
  'Created At': 'Tanggal Dibuat',
}

function getFilterColumnLabel(key) {
  const col = filterColumns.value.find(c => c.key === key)
  if (!col) return key
  return filterLabelMap[col.label] || col.label
}

function buildStatsParams() {
  const params = {}
  if (stateData.value.filters.status) params.status = stateData.value.filters.status
  if (stateData.value.filters.academic_year) params.academic_year = stateData.value.filters.academic_year
  if (stateData.value.filters.semester) params.semester = stateData.value.filters.semester
  if (searchNim.value) params.search_nim = searchNim.value
  if (searchName.value) params.search_name = searchName.value
  if (searchCourseCode.value) params.search_course_code = searchCourseCode.value
  if (advancedFilters.value.length) {
    params.filters = JSON.stringify(serializeFilters())
    params.filter_logic = filterLogic.value
  }
  params.per_page = stateData.value.perPage
  return params
}

const operatorLabels = {
  equals: '=', not_equals: '≠', contains: 'mengandung', not_contains: 'tidak mengandung',
  starts_with: 'dimulai dengan', ends_with: 'berakhir dengan',
  greater_than: '>', less_than: '<', greater_equal: '≥', less_equal: '≤',
  between: 'antara', in: 'dalam', is_null: 'kosong', is_not_null: 'tidak kosong',
}

const defaultOperators = {
  string: ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_null', 'is_not_null'],
  enum: ['equals', 'not_equals', 'in', 'is_null', 'is_not_null'],
  number: ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_equal', 'less_equal', 'between', 'is_null', 'is_not_null'],
  date: ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_equal', 'less_equal', 'between', 'is_null', 'is_not_null'],
}

async function loadFilterColumns() {
  try {
    const res = await api.get('/api/krs/filter-columns')
    filterColumns.value = res.data
  } catch (e) { console.error('Load filter columns failed', e) }
}

function removeFilter(index) {
  advancedFilters.value.splice(index, 1)
  stateData.value.page = 1
  stateData.value.enrollments = []
  load()
  loadStats()
}

function getFilterLabel(filter) {
  const col = filterColumns.value.find(c => c.key === filter.column)
  return col ? getFilterColumnLabel(col.key) : filter.column
}

function getOperatorLabel(op) {
  return operatorLabels[op] || op
}

function isFilterActive() {
  return advancedFilters.value.length > 0
}

function clearFilters() {
  advancedFilters.value = []
  advancedSorts.value = []
  stateData.value.page = 1
  stateData.value.enrollments = []
  sort.value = { field: 'id', direction: 'desc' }
  load()
  loadStats()
}

function resetAllFilters() {
  advancedFilters.value = []
  advancedSorts.value = []
  sort.value = { field: 'id', direction: 'desc' }
}

function getPlaceholder(column, operator) {
  const col = filterColumns.value.find(c => c.key === column)
  if (!col) return 'Masukkan nilai...'
  if (col.type === 'enum') {
    const opts = col.options || []
    return opts.length ? `Pilih (${opts.join(', ')})` : 'Masukkan nilai...'
  }
  if (col.type === 'number') return 'Masukkan angka...'
  if (col.type === 'date') return 'YYYY-MM-DD...'
  return 'Masukkan teks...'
}

function onColumnChange() {
  const col = filterColumns.value.find(c => c.key === newFilter.value.column)
  if (col) {
    newFilter.value.type = col.type
    newFilter.value.operator = defaultOperators[col.type]?.[0] || 'equals'
    newFilter.value.value = ''
    newFilterMin.value = ''
    newFilterMax.value = ''
    newFilterMultiValues.value = []
  }
}

function getRowOperators(filter) {
  if (!filter.column) return ['equals']
  const col = filterColumns.value.find(c => c.key === filter.column)
  return defaultOperators[col?.type] || ['equals']
}

function getEnumOptions(columnKey) {
  const col = filterColumns.value.find(c => c.key === columnKey)
  return col?.options || []
}

function onRowColumnChange(index) {
  const filter = advancedFilters.value[index]
  if (!filter) return
  const col = filterColumns.value.find(c => c.key === filter.column)
  if (col) {
    filter.operator = defaultOperators[col.type]?.[0] || 'equals'
    filter.value = ''
    filter.min = ''
    filter.max = ''
  }
}

function onRowOperatorChange(index) {
  const filter = advancedFilters.value[index]
  if (!filter) return
  filter.value = ''
  filter.min = ''
  filter.max = ''
}

function addFilter() {
  if (!newFilter.value.column) return
  const col = filterColumns.value.find(c => c.key === newFilter.value.column)
  const filterEntry = {
    column: newFilter.value.column,
    operator: newFilter.value.operator,
    type: col?.type || 'string',
  }

  if (newFilter.value.operator === 'between') {
    filterEntry.value = [newFilterMin.value, newFilterMax.value].filter(v => v !== '')
  } else if (newFilter.value.operator === 'in') {
    filterEntry.value = newFilterMultiValues.value.filter(v => v !== '')
  } else {
    filterEntry.value = newFilter.value.value
  }

  advancedFilters.value.push(filterEntry)
  resetNewFilter()
  stateData.value.page = 1
  stateData.value.enrollments = []
  load()
  loadStats()
}

function resetNewFilter() {
  newFilter.value = { column: '', operator: 'equals', value: '' }
  newFilterMin.value = ''
  newFilterMax.value = ''
  newFilterMultiValues.value = []
}

function applyFilters() {
  showFilterPanel.value = false
  stateData.value.page = 1
  stateData.value.enrollments = []
  load()
  loadStats()
}

function serializeFilters() {
  return advancedFilters.value.map(f => {
    const filter = { column: f.column, operator: f.operator }
    if (f.operator === 'between') {
      filter.value = [f.min, f.max].filter(v => v !== '')
    } else if (f.operator === 'in') {
      filter.value = Array.isArray(f.value) ? f.value : f.value ? [f.value] : []
    } else if (f.operator === 'is_null' || f.operator === 'is_not_null') {
      filter.value = null
    } else {
      filter.value = f.value
    }
    return filter
  })
}

const currentOperators = computed(() => {
  if (!newFilter.value.column) return ['equals']
  const col = filterColumns.value.find(c => c.key === newFilter.value.column)
  return defaultOperators[col?.type] || ['equals']
})


async function resetFilters() {
  stateData.value.filters = { status: '', academic_year: '', semester: '' }
  searchNim.value = ''
  searchName.value = ''
  searchCourseCode.value = ''
  advancedSorts.value = []
  advancedFilters.value = []
  stateData.value.page = 1
  stateData.value.enrollments = []
  sort.value = { field: 'id', direction: 'desc' }
  await load()
  await loadStats()
}

function addSort() {
  const available = filterColumns.value.map(c => c.key).filter(k => !advancedSorts.value.find(s => s.field === k))
  if (available.length) {
    advancedSorts.value.push({ field: available[0], direction: 'asc' })
  }
}

function removeSort(index) {
  advancedSorts.value.splice(index, 1)
}

function moveSortUp(index) {
  if (index > 0) {
    [advancedSorts.value[index], advancedSorts.value[index - 1]] = [advancedSorts.value[index - 1], advancedSorts.value[index]]
  }
}

function moveSortDown(index) {
  if (index < advancedSorts.value.length - 1) {
    [advancedSorts.value[index], advancedSorts.value[index + 1]] = [advancedSorts.value[index + 1], advancedSorts.value[index]]
  }
}


async function applyAdvancedSort() {
  showSortPanel.value = false
  if (advancedSorts.value.length) {
    sort.value = advancedSorts.value[0]
  }
  stateData.value.page = 1
  stateData.value.enrollments = []
  await load()
}

async function load() {
  stateData.value.loading = true
  try {
    const params = {
      per_page: stateData.value.perPage,
    }
    if (stateData.value.nextCursor) {
      params.cursor = stateData.value.nextCursor
    }
    if (stateData.value.filters.status) params.status = stateData.value.filters.status
    if (stateData.value.filters.academic_year) params.academic_year = stateData.value.filters.academic_year
    if (stateData.value.filters.semester) params.semester = stateData.value.filters.semester
    if (searchNim.value) params.search_nim = searchNim.value
    if (searchName.value) params.search_name = searchName.value
    if (searchCourseCode.value) params.search_course_code = searchCourseCode.value
    if (advancedSorts.value.length) {
      params.sorts = JSON.stringify(advancedSorts.value)
    }
    if (advancedFilters.value.length) {
      params.filters = JSON.stringify(serializeFilters())
      params.filter_logic = filterLogic.value
    }
    if (sort.value.field !== 'id') {
      params.sort = sort.value.field
      params.direction = sort.value.direction
    }

    const res = await api.get('/api/krs', { params })
    stateData.value.enrollments = res.data.data || []
    stateData.value.total = res.data.total
    stateData.value.totalPages = res.data.last_page
    stateData.value.nextCursor = res.data.next_cursor
    stateData.value.prevCursor = stateData.value.nextCursor ? stateData.value.nextCursor : null
  } catch (e) {
    error.value = [{ field: 'Error', message: e.message }]
  } finally {
    stateData.value.loading = false
  }
}

async function loadStats() {
  loadingStats.value = true
  try {
    const res = await api.get('/api/krs/stats', { params: buildStatsParams() })
    const d = res.data || {}
    stats.value = { total: d.total || 0, approved: d.approved || 0, submitted: d.submitted || 0, draft: d.draft || 0, rejected: d.rejected || 0 }
  } catch (e) {
    console.error('Stats load failed', e)
  } finally {
    loadingStats.value = false
  }
}

function handleSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    stateData.value.page = 1
    stateData.value.enrollments = []
    load()
  }, 800)
}

async function searchStudents(query) {
  if (!query || query.length < 2) { studentResults.value = []; return }
  searchingStudent.value = true
  try {
    const res = await api.get('/api/krs/students/search', { params: { q: query } })
    studentResults.value = res.data
  } catch (e) { console.error(e) }
  finally { searchingStudent.value = false }
}

async function searchCourses(query) {
  if (!query || query.length < 2) { courseResults.value = []; return }
  searchingCourse.value = true
  try {
    const res = await api.get('/api/krs/courses/search', { params: { q: query } })
    courseResults.value = res.data
  } catch (e) { console.error(e) }
  finally { searchingCourse.value = false }
}


function selectStudent(student) {
  form.value.student_id = student.id
  form.value.student_nim = student.nim
  form.value.student_name = student.name
  studentResults.value = []
  activeTab.value = 'select'
}

function selectCourse(course) {
  form.value.course_id = course.id
  form.value.course_code = course.code
  form.value.course_name = course.name
  form.value.course_credits = course.credits
  courseResults.value = []
  activeTab.value = 'select'
}

const isExporting = ref(false)
const exportProgress = ref({ status: '', progress: 0, rows: 0 })
let exportPollTimer = null
let currentExportToken = null
const exportSuccessMsg = ref('')

async function exportData() {
  isExporting.value = true
  exportProgress.value = { status: 'init', progress: 0, rows: 0 }

  try {
    const params = {}
    if (advancedFilters.value.length) {
      params.filters = JSON.stringify(serializeFilters())
      params.filter_logic = filterLogic.value
    }
    const res = await api.get('/api/krs/export/init', { params })
    const token = res.data.download_token
    currentExportToken = token

    localStorage.setItem('lastExportToken', token)
    localStorage.setItem('lastExportState', JSON.stringify({
      token,
      status: 'processing',
      progress: 0,
      rows: 0,
      startedAt: Date.now(),
    }))

    exportPollTimer = setInterval(async () => {
      try {
        const st = await api.get(`/api/krs/export/status/${token}`)
        const p = st.data
        exportProgress.value = {
          status: p.status || 'processing',
          progress: p.progress ?? 0,
          rows: p.processed_rows ?? 0,
        }
        localStorage.setItem('lastExportState', JSON.stringify({
          token,
          status: p.status,
          progress: p.progress ?? 0,
          rows: p.processed_rows ?? 0,
          startedAt: Date.now(),
        }))
        if (p.status === 'completed') {
          clearInterval(exportPollTimer)
          exportPollTimer = null
          isExporting.value = false
          exportProgress.value = { status: '', progress: 0, rows: 0 }
          localStorage.removeItem('lastExportToken')
          localStorage.removeItem('lastExportState')
          const dlRes = await api.get(`/api/krs/export/download/${token}`, { responseType: 'blob' })
          const url = window.URL.createObjectURL(new Blob([dlRes.data]))
          const a = document.createElement('a')
          a.href = url
          a.download = `krs_export_${Date.now()}.csv`
          a.click()
          window.URL.revokeObjectURL(url)
          exportSuccessMsg.value = `✅ CSV berhasil diunduh — ${p.processed_rows?.toLocaleString() ?? 0} baris`
          setTimeout(() => { exportSuccessMsg.value = '' }, 5000)
        } else if (p.status === 'failed') {
          clearInterval(exportPollTimer)
          exportPollTimer = null
          isExporting.value = false
          exportProgress.value = { status: 'failed', progress: p.progress ?? 0, rows: p.processed_rows ?? 0 }
          console.error('Export failed', p)
        }
      } catch (e) {
        console.error('Export poll error', e)
      }
    }, 2000)
  } catch (e) {
    isExporting.value = false
    exportProgress.value = { status: 'error', progress: 0, rows: 0 }
    console.error('Export init failed', e)
  }
}

async function resumeExportFromStorage() {
  const saved = localStorage.getItem('lastExportState')
  if (!saved) return
  try {
    const state = JSON.parse(saved)
    if (!state.token || state.status === 'completed' || state.status === 'failed') return

    const st = await api.get(`/api/krs/export/status/${state.token}`)
    const p = st.data

    exportProgress.value = {
      status: p.status || 'processing',
      progress: p.progress ?? 0,
      rows: p.processed_rows ?? 0,
    }

    if (p.status === 'completed') {
      const dlRes = await api.get(`/api/krs/export/download/${state.token}`, { responseType: 'blob' })
      const url = window.URL.createObjectURL(new Blob([dlRes.data]))
      const a = document.createElement('a')
      a.href = url
      a.download = `krs_export_${Date.now()}.csv`
      a.click()
      window.URL.revokeObjectURL(url)
      isExporting.value = false
      exportProgress.value = { status: '', progress: 0, rows: 0 }
      localStorage.removeItem('lastExportToken')
      localStorage.removeItem('lastExportState')
      exportSuccessMsg.value = `✅ CSV berhasil diunduh — ${p.processed_rows?.toLocaleString() ?? 0} baris`
      setTimeout(() => { exportSuccessMsg.value = '' }, 5000)
      return
    }

    if (p.status === 'failed') {
      isExporting.value = false
      exportProgress.value = { status: 'failed', progress: p.progress ?? 0, rows: p.processed_rows ?? 0 }
      localStorage.removeItem('lastExportToken')
      localStorage.removeItem('lastExportState')
      return
    }

    isExporting.value = true
    exportPollTimer = setInterval(async () => {
      try {
        const st2 = await api.get(`/api/krs/export/status/${state.token}`)
        const p2 = st2.data
        exportProgress.value = {
          status: p2.status || 'processing',
          progress: p2.progress ?? 0,
          rows: p2.processed_rows ?? 0,
        }
        if (p2.status === 'completed') {
          clearInterval(exportPollTimer)
          exportPollTimer = null
          isExporting.value = false
          exportProgress.value = { status: '', progress: 0, rows: 0 }
          localStorage.removeItem('lastExportToken')
          localStorage.removeItem('lastExportState')
          const dlRes = await api.get(`/api/krs/export/download/${state.token}`, { responseType: 'blob' })
          const url = window.URL.createObjectURL(new Blob([dlRes.data]))
          const a = document.createElement('a')
          a.href = url
          a.download = `krs_export_${Date.now()}.csv`
          a.click()
          window.URL.revokeObjectURL(url)
          exportSuccessMsg.value = `✅ CSV berhasil diunduh — ${p2.processed_rows?.toLocaleString() ?? 0} baris`
          setTimeout(() => { exportSuccessMsg.value = '' }, 5000)
        } else if (p2.status === 'failed') {
          clearInterval(exportPollTimer)
          exportPollTimer = null
          isExporting.value = false
          exportProgress.value = { status: 'failed', progress: p2.progress ?? 0, rows: p2.processed_rows ?? 0 }
          localStorage.removeItem('lastExportToken')
          localStorage.removeItem('lastExportState')
        }
      } catch (e) {
        console.error('Export resume poll error', e)
      }
    }, 2000)
  } catch (e) {
    console.error('Resume export failed', e)
    localStorage.removeItem('lastExportToken')
    localStorage.removeItem('lastExportState')
  }
}

function openCreate() {
  editingId.value = null
  form.value = { status: 'DRAFT', academic_year: '2025-2026', semester: 'GANJIL', student_id: null, course_id: null, student_nim: '', student_name: '', course_code: '', course_name: '', course_credits: 3 }
  newStudent.value = { nim: '', name: '', email: '' }
  newCourse.value = { code: '', name: '', credits: 3 }
  activeTab.value = 'select'
  showForm.value = true
  error.value = []
}

function fieldToLabel(field) {
  const labels = {
    student_nim: 'NIM',
    student_name: 'Nama Mahasiswa',
    student_email: 'Email Mahasiswa',
    course_code: 'Kode MK',
    course_name: 'Nama MK',
    course_credits: 'SKS',
    academic_year: 'Tahun Ajaran',
    semester: 'Semester',
    status: 'Status',
    grade: 'Nilai',
    gpa_points: 'IPK',
    nim: 'NIM',
    name: 'Nama',
    email: 'Email',
    phone: 'No. Telepon',
    code: 'Kode MK',
    credits: 'SKS',
  }
  return labels[field] || field
}

function openEdit(e) {
  editingId.value = e.id
  form.value = { ...e, student_nim: e.student?.nim || '', student_name: e.student?.name || '', course_code: e.course?.code || '', course_name: e.course?.name || '', course_credits: e.course?.credits || 3 }
  activeTab.value = 'edit'
  showForm.value = true
  error.value = []
}

async function save() {
  error.value = []
  krsMain.errors.value = {}
  const mainOk = krsMain.validateAll()
  if (!mainOk) {
    error.value = Object.entries(krsMain.errors.value).map(([field, msg]) => ({ field: fieldToLabel(field), message: msg }))
    return
  }
  try {
    saving.value = true
    if (editingId.value) {
      await api.put(`/api/krs/${editingId.value}`, form.value)
      toast.value = 'KRS berhasil diperbarui'
      toastType.value = 'success'
    } else {
      const payload = {
        student_nim: form.value.student_nim || newStudent.value.nim,
        student_name: form.value.student_name || newStudent.value.name,
        student_email: newStudent.value.email,
        course_code: form.value.course_code || newCourse.value.code,
        course_name: form.value.course_name || newCourse.value.name,
        course_credits: form.value.course_credits || newCourse.value.credits,
        academic_year: form.value.academic_year,
        semester: form.value.semester,
        status: form.value.status,
      }
      await api.post('/api/krs', payload)
      toast.value = 'KRS baru berhasil ditambahkan'
      toastType.value = 'success'
    }
    setTimeout(() => { showForm.value = false; toast.value = ''; toastType.value = '' }, 4000)
    await load()
    await loadStats()
  } catch (e) {
    const data = e.response?.data
    if (data && data.errors) {
      krsMain.errors.value = {}
      for (const [field, msgs] of Object.entries(data.errors)) {
        krsMain.errors.value[field] = Array.isArray(msgs) ? msgs[0] : msgs
      }
      error.value = Object.entries(krsMain.errors.value).map(([field, msg]) => ({ field: fieldToLabel(field), message: msg }))
    } else {
      error.value = [{ field: 'Error', message: data?.error || e.message }]
    }
  } finally {
    saving.value = false
  }
}

function confirmDelete(id) { deleteConfirm.value = id }

async function remove() {
  if (!deleteConfirm.value) return
  try {
    await api.delete(`/api/krs/${deleteConfirm.value}`)
    deleteConfirm.value = null
    success.value = 'Enrollment deleted successfully'
    setTimeout(() => success.value = '', 3000)
    await load()
    await loadStats()
  } catch (e) { error.value = [{ field: 'Error', message: e.message }] }
}

async function toggleQuickStatus(value) {
  stateData.value.filters.status = stateData.value.filters.status === value ? '' : value
  stateData.value.page = 1; stateData.value.enrollments = []
  await load(); await loadStats()
}

async function toggleQuickSemester(value) {
  stateData.value.filters.semester = stateData.value.filters.semester === value ? '' : value
  stateData.value.page = 1; stateData.value.enrollments = []
  await load(); await loadStats()
}

async function sortColumn(field) {
  if (sort.value.field === field) {
    sort.value.direction = sort.value.direction === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value.field = field
    sort.value.direction = 'asc'
  }
  const existing = advancedSorts.value.find(s => s.field === field)
  if (existing) {
    existing.direction = sort.value.direction
  } else {
    advancedSorts.value.unshift({ field, direction: sort.value.direction })
  }
  stateData.value.page = 1
  stateData.value.enrollments = []
  await load()
}

function getSortIndicator(field) {
  if (sort.value.field === field) {
    return sort.value.direction === 'asc' ? '↑' : '↓'
  }
  const adv = advancedSorts.value.find(s => s.field === field)
  if (adv) return adv.direction === 'asc' ? '↑' : '↓'
  return '↕'
}

async function loadNextPage() {
  if (!stateData.value.nextCursor) return
  stateData.value.page++
  await load()
}

async function loadPrevPage() {
  if (stateData.value.page <= 1) return
  stateData.value.page--
  await load()
}


watch([() => stateData.value.page, () => stateData.value.perPage], async () => {
  await load()
})

onMounted(async () => {
  await loadFilterColumns()
  await load()
  await loadStats()
  await resumeExportFromStorage()
})

onUnmounted(() => {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (exportPollTimer) clearInterval(exportPollTimer)
})

async function saveNewStudent() {
  error.value = []
  krsStudent.errors.value = {}
  if (!krsStudent.validateAll()) {
    error.value = Object.entries(krsStudent.errors.value).map(([field, msg]) => ({ field: fieldToLabel(field), message: msg }))
    return
  }
  try {
    const res = await api.post('/api/students', { nim: newStudent.value.nim, name: newStudent.value.name, email: newStudent.value.email, phone: newStudent.value.phone })
    form.value.student_id = res.data.id
    form.value.student_nim = res.data.nim
    form.value.student_name = res.data.name
    toast.value = `Mahasiswa ${newStudent.value.name} berhasil ditambahkan`
    toastType.value = 'success'
    newStudent.value = { nim: '', name: '', email: '', phone: '' }
    krsStudent.errors.value = {}
    setTimeout(() => { toast.value = ''; toastType.value = '' }, 4000)
    activeTab.value = 'select'
  } catch (e) {
    const data = e.response?.data
    if (data && data.errors) { krsStudent.errors.value = data.errors; error.value = Object.entries(data.errors).map(([field, msg]) => ({ field: fieldToLabel(field), message: Array.isArray(msg) ? msg[0] : msg })) }
    else { error.value = [{ field: 'Error', message: data?.message || e.message }] }
  }
}

async function saveNewCourse() {
  error.value = []
  krsCourse.errors.value = {}
  if (!krsCourse.validateAll()) {
    error.value = Object.entries(krsCourse.errors.value).map(([field, msg]) => ({ field: fieldToLabel(field), message: msg }))
    return
  }
  try {
    const res = await api.post('/api/courses', { code: newCourse.value.code, name: newCourse.value.name, credits: newCourse.value.credits })
    form.value.course_id = res.data.id
    form.value.course_code = res.data.code
    form.value.course_name = res.data.name
    form.value.course_credits = res.data.credits
    toast.value = `Mata Kuliah ${newCourse.value.code} berhasil ditambahkan`
    toastType.value = 'success'
    newCourse.value = { code: '', name: '', credits: 3 }
    krsCourse.errors.value = {}
    setTimeout(() => { toast.value = ''; toastType.value = '' }, 4000)
    activeTab.value = 'select'
  } catch (e) {
    const data = e.response?.data
    if (data && data.errors) { krsCourse.errors.value = data.errors; error.value = Object.entries(data.errors).map(([field, msg]) => ({ field: fieldToLabel(field), message: Array.isArray(msg) ? msg[0] : msg })) }
    else { error.value = [{ field: 'Error', message: data?.message || e.message }] }
  }
}
</script>

<template>
  <div class="view animate-in">
    <!-- Stats Row -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon" style="background:#d1fae5;color:#10b981">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="stat-info"><span class="stat-value">{{ formatNum(animTotal) }}</span><span class="stat-label">Total KRS</span></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#dbeafe;color:#3b82f6">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div class="stat-info"><span class="stat-value">{{ formatNum(animSubmitted) }}</span><span class="stat-label">Submitted</span></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#d1fae5;color:#10b981">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-info"><span class="stat-value">{{ formatNum(animApproved) }}</span><span class="stat-label">Approved</span></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;color:#ef4444">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-info"><span class="stat-value">{{ formatNum(animRejected) }}</span><span class="stat-label">Rejected</span></div>
      </div>
    </div>

    <!-- Quick Filters -->
    <div class="quick-filters">
      <span class="quick-label">Quick Filter:</span>
      <button v-for="s in statuses" :key="s.value" class="quick-btn" :class="{ active: stateData.filters.status === s.value }" :style="stateData.filters.status === s.value ? { background: s.bg, color: s.color, border: `1px solid ${s.color}` } : {}" @click="toggleQuickStatus(s.value)">{{ s.label }}</button>
      <div class="quick-separator"></div>
      <button v-for="s in semesters" :key="s" class="quick-btn semester" :class="{ active: stateData.filters.semester === s }" @click="toggleQuickSemester(s)">{{ s }}</button>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-box">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:0.5;flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" placeholder="NIM" v-model="searchNim" @input="handleSearch" />
          <input type="text" placeholder="Nama Mahasiswa" v-model="searchName" @input="handleSearch" />
          <input type="text" placeholder="Kode MK" v-model="searchCourseCode" @input="handleSearch" />
        </div>
        <select v-model="stateData.filters.semester" class="filter-select" @change="load">
          <option value="">All Semesters</option>
          <option v-for="s in semesters" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="stateData.filters.academic_year" class="filter-select" @change="load">
          <option value="">All Years</option>
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="stateData.filters.status" class="filter-select" @change="load">
          <option value="">All Status</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button class="btn btn-ghost" @click="resetFilters">Reset</button>
        <button class="btn btn-ghost" @click="showFilterPanel = true" :style="isFilterActive() ? { color: '#f59e0b', borderColor: '#f59e0b' } : {}">
          {{ isFilterActive() ? `Filter(${advancedFilters.length})` : 'Filter Lanjutan' }}
        </button>
        <button v-if="isFilterActive()" class="btn btn-ghost btn-xs" @click="clearFilters" style="color:#ef4444;border-color:#ef4444">Hapus Filter</button>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-ghost" @click="exportData" :disabled="isExporting">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Export CSV
        </button>
        <button class="btn btn-primary" @click="openCreate">+ New KRS</button>
      </div>
    </div>

    <!-- Export Success Toast -->
    <div v-if="toast" :class="['global-toast', `toast-${toastType}`]">{{ toast }}</div>
    <div v-if="exportSuccessMsg" class="export-success-toast">{{ exportSuccessMsg }}</div>

    <!-- Export Progress -->
    <div v-if="isExporting || exportProgress.status" class="export-progress-bar">
      <div class="export-progress-info">
        <span class="export-status">{{
          exportProgress.status === 'completed' ? '✅ Selesai' :
          exportProgress.status === 'failed' ? '❌ Gagal' :
          exportProgress.status === 'error' ? '❌ Error' :
          '📦 Memproses...'
        }}</span>
        <span class="export-rows">{{ exportProgress.rows.toLocaleString() }} baris</span>
      </div>
      <div class="export-progress-track">
        <div class="export-progress-fill" :style="{ width: exportProgress.progress + '%' }"></div>
      </div>
      <span class="export-pct">{{ exportProgress.progress }}%</span>
    </div>

    <!-- Active Sorts -->
    <div v-if="advancedSorts.length" class="sort-bar">
      <span class="sort-bar-label">Diurutkan berdasarkan:</span>
      <span v-for="(s, i) in advancedSorts" :key="'s'+i" class="sort-tag">
        {{ sortColumnMap[s.field] || s.field }}
        <button class="sort-tag-btn" @click="s.direction = s.direction === 'asc' ? 'desc' : 'asc'">{{ s.direction === 'asc' ? '↑' : '↓' }}</button>
        <button class="sort-tag-remove" @click="removeSort(i)">&times;</button>
      </span>
      <button v-if="advancedSorts.length === 1" class="btn btn-ghost btn-xs" @click="showSortPanel = true">+ Secondary</button>
    </div>

    <!-- Active Filters Chips -->
    <div v-if="advancedFilters.length" class="filter-chips" style="margin-top:8px">
      <span class="chip-label">Difilter berdasarkan:</span>
      <span v-for="(f, i) in advancedFilters" :key="'f'+i" class="filter-chip">
        {{ getFilterLabel(f) }} {{ getOperatorLabel(f.operator) }} {{ f.value }}
        <button class="chip-remove" @click="removeFilter(i)">&times;</button>
      </span>
      <button class="btn btn-ghost btn-xs" @click="showFilterPanel = true" style="color:#f59e0b;border-color:#f59e0b">+ Tambah Filter</button>
    </div>

    <!-- Data Table -->
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th class="sortable" :class="{ active: sort.field === 'nim' }" @click="sortColumn('nim')">NIM <span class="sort-indicator">{{ getSortIndicator('nim') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'student_name' }" @click="sortColumn('student_name')">Nama <span class="sort-indicator">{{ getSortIndicator('student_name') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'course_code' }" @click="sortColumn('course_code')">Kode MK <span class="sort-indicator">{{ getSortIndicator('course_code') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'course_name' }" @click="sortColumn('course_name')">Nama MK <span class="sort-indicator">{{ getSortIndicator('course_name') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'semester' }" @click="sortColumn('semester')">Semester <span class="sort-indicator">{{ getSortIndicator('semester') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'academic_year' }" @click="sortColumn('academic_year')">Tahun <span class="sort-indicator">{{ getSortIndicator('academic_year') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'status' }" @click="sortColumn('status')">Status <span class="sort-indicator">{{ getSortIndicator('status') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'grade' }" @click="sortColumn('grade')">Nilai <span class="sort-indicator">{{ getSortIndicator('grade') }}</span></th>
            <th class="sortable" :class="{ active: sort.field === 'gpa_points' }" @click="sortColumn('gpa_points')">IPK <span class="sort-indicator">{{ getSortIndicator('gpa_points') }}</span></th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="stateData.loading"><td colspan="10" class="empty-state"><div class="spinner-ring"></div>Loading...</td></tr>
          <tr v-else-if="!stateData.enrollments?.length"><td colspan="10" class="empty-state">Tidak ada data KRS ditemukan</td></tr>
          <tr v-for="e in stateData.enrollments" :key="e.id">
            <td><span class="mono">{{ e.student?.nim || '-' }}</span></td>
            <td><div class="user-cell"><div class="avatar" :style="{ background: 'linear-gradient(135deg, #3b82f6, #8b5cf6)' }">{{ e.student?.name?.split(' ').map(n => n[0]).join('').slice(0, 2) || '?' }}</div><span class="name">{{ e.student?.name || '-' }}</span></div></td>
            <td><span class="mono">{{ e.course?.code || '-' }}</span></td>
            <td><span class="course-subtitle">{{ e.course?.name || '-' }}</span></td>
            <td><span class="chip">{{ e.semester }}</span></td>
            <td><span class="chip">{{ e.academic_year }}</span></td>
            <td><span class="status-badge" :style="{ background: statusMap[e.status]?.bg, color: statusMap[e.status]?.color }">{{ e.status }}</span></td>
            <td><span v-if="e.grade" class="grade-badge" :class="{ 'grade-a': ['A','A-'].includes(e.grade), 'grade-b': ['B+','B','B-'].includes(e.grade), 'grade-c': ['C','C-','D'].includes(e.grade), 'grade-f': ['E','I','K'].includes(e.grade) }">{{ e.grade }}</span><span v-else class="text-muted">-</span></td>
            <td><span v-if="e.gpa_points != null" class="gpa-badge" :class="{ 'gpa-high': Number(e.gpa_points) >= 3.0, 'gpa-mid': Number(e.gpa_points) >= 2.0, 'gpa-low': Number(e.gpa_points) < 2.0 }">{{ Number(e.gpa_points).toFixed(2) }}</span><span v-else class="text-muted">-</span></td>
            <td><div class="action-btns"><button class="btn-icon btn-edit" @click="openEdit(e)" title="Edit">&#9998;&#xFE0F;</button><button class="btn-icon btn-delete" @click="confirmDelete(e.id)" title="Delete">&#x1F5D1;&#xFE0F;</button></div></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="stateData.enrollments.length" class="pagination-bar">
      <div class="pagination-info">
        <span>{{ ((stateData.page - 1) * stateData.perPage + 1) }}-{{ Math.min(stateData.page * stateData.perPage, stateData.total) }} of {{ stateData.total.toLocaleString() }}</span>
        <select v-model="stateData.perPage" class="per-page-select">
          <option value="10">10 / page</option>
          <option value="25">25 / page</option>
          <option value="50">50 / page</option>
          <option value="100">100 / page</option>
        </select>
      </div>
      <div class="pagination-controls">
        <button class="page-btn" :disabled="!stateData.nextCursor && stateData.page <= 1" @click="loadPrevPage()">← Prev</button>
        <button class="page-btn" :disabled="!stateData.nextCursor" @click="loadNextPage()">Next →</button>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="showForm" class="modal-overlay modal-fade" @click.self="showForm = false">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h3>{{ editingId ? 'Edit KRS' : 'New KRS Entry' }}</h3>
          <button class="btn-close" @click="showForm = false">&times;</button>
        </div>
        <div class="modal-body">
          <div v-if="error.length" class="alert alert-error">
            <div v-for="(e, i) in error" :key="i" style="margin-bottom: 4px;">{{ e.field }}: {{ e.message }}</div>
          </div>
          <div v-if="!editingId" class="krs-tabs">
            <button class="tab-btn" :class="{ active: activeTab === 'select' }" @click="activeTab = 'select'">Pilih Data</button>
            <button class="tab-btn" :class="{ active: activeTab === 'new-student' }" @click="activeTab = 'new-student'">+ Mahasiswa Baru</button>
            <button class="tab-btn" :class="{ active: activeTab === 'new-course' }" @click="activeTab = 'new-course'">+ Mata Kuliah Baru</button>
          </div>
          <div v-if="activeTab === 'select'" class="form-grid">
            <div class="form-group" :class="{ 'field-error': krsMain.errors.student_nim }">
              <label>Mahasiswa <span class="required">*</span></label>
              <div class="search-wrapper">
                <NumericInput
                  :model-value="form.student_nim"
                  :max-length="12"
                  placeholder="Cari NIM atau nama..."
                  @update:modelValue="form.student_nim = $event; searchStudents(form.student_nim); krsMain.validateField('student_nim')"
                  @blur="krsMain.validateField('student_nim')"
                />
                <button class="btn-link" @click="activeTab = 'new-student'">+ Baru</button>
              </div>
              <div v-if="searchingStudent" class="searching">Searching...</div>
              <ul v-if="studentResults.length" class="search-results">
                <li v-for="s in studentResults" :key="s.id" @click="selectStudent(s)"><span class="mono">{{ s.nim }}</span> - {{ s.name }}</li>
              </ul>
              <small v-if="krsMain.errors.student_nim" class="field-msg">{{ krsMain.errors.student_nim }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsMain.errors.course_code }">
              <label>Mata Kuliah <span class="required">*</span></label>
              <div class="search-wrapper">
                <input v-model="form.course_code" placeholder="Cari kode MK..."
                       @input="searchCourses(form.course_code); krsMain.validateField('course_code')"
                       @blur="krsMain.validateField('course_code')" />
                <button class="btn-link" @click="activeTab = 'new-course'">+ Baru</button>
              </div>
              <div v-if="searchingCourse" class="searching">Searching...</div>
              <ul v-if="courseResults.length" class="search-results">
                <li v-for="c in courseResults" :key="c.id" @click="selectCourse(c)"><span class="mono">{{ c.code }}</span> - {{ c.name }}</li>
              </ul>
              <small v-if="krsMain.errors.course_code" class="field-msg">{{ krsMain.errors.course_code }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsMain.errors.academic_year }">
              <label>Tahun Ajaran <span class="required">*</span></label>
              <select v-model="form.academic_year" @change="krsMain.validateField('academic_year')"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
              <small v-if="krsMain.errors.academic_year" class="field-msg">{{ krsMain.errors.academic_year }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsMain.errors.semester }">
              <label>Semester <span class="required">*</span></label>
              <select v-model="form.semester" @change="krsMain.validateField('semester')"><option v-for="s in semesters" :key="s" :value="s">{{ s }}</option></select>
              <small v-if="krsMain.errors.semester" class="field-msg">{{ krsMain.errors.semester }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsMain.errors.status }">
              <label>Status</label>
              <select v-model="form.status" @change="krsMain.validateField('status')"><option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option></select>
              <small v-if="krsMain.errors.status" class="field-msg">{{ krsMain.errors.status }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsMain.errors.grade }">
              <label>Nilai</label>
              <select v-model="form.grade" @change="krsMain.validateField('grade')"><option value="">-</option><option v-for="g in ['A','A-','B+','B','B-','C','C-','D','E','I','S','K']" :key="g" :value="g">{{ g }}</option></select>
              <small v-if="krsMain.errors.grade" class="field-msg">{{ krsMain.errors.grade }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsMain.errors.gpa_points }">
              <label>IPK</label>
              <input v-model.number="form.gpa_points" type="number" min="0" max="4" step="0.01" placeholder="0.00 - 4.00"
                     @blur="krsMain.validateField('gpa_points')" @input="krsMain.validateField('gpa_points')" />
              <small v-if="krsMain.errors.gpa_points" class="field-msg">{{ krsMain.errors.gpa_points }}</small>
            </div>
          </div>
          <div v-if="activeTab === 'new-student'" class="form-grid">
            <div class="form-group" :class="{ 'field-error': krsStudent.errors.nim }">
              <label>NIM <span class="required">*</span></label>
              <NumericInput
                v-model="newStudent.nim"
                :max-length="12"
                placeholder="8-12 digit"
                @blur="krsStudent.validateField('nim')"
                @change="krsStudent.validateField('nim')"
              />
              <small v-if="krsStudent.errors.nim" class="field-msg">{{ krsStudent.errors.nim }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsStudent.errors.name }">
              <label>Nama <span class="required">*</span></label>
              <input v-model="newStudent.name" placeholder="Nama lengkap" minlength="3" maxlength="100"
                     @blur="krsStudent.validateField('name')" @input="krsStudent.validateField('name')" />
              <small v-if="krsStudent.errors.name" class="field-msg">{{ krsStudent.errors.name }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsStudent.errors.email }">
              <label>Email <span class="required">*</span></label>
              <input v-model="newStudent.email" type="email" placeholder="email@university.edu"
                     @blur="krsStudent.validateField('email')" @input="krsStudent.validateField('email')" />
              <small v-if="krsStudent.errors.email" class="field-msg">{{ krsStudent.errors.email }}</small>
            </div>
            <div class="form-group"><label>Telepon</label><input v-model="newStudent.phone" placeholder="+60xxxxxxxxx" type="tel" pattern="[0-9+]*" inputmode="numeric" @input="newStudent.phone = newStudent.phone.replace(/[^0-9+]/g, '')" /></div>
            <div class="form-group" style="grid-column: span 2"><button class="btn btn-primary" @click="saveNewStudent">Simpan Mahasiswa</button><button class="btn btn-ghost" @click="activeTab = 'select'">Kembali</button></div>
          </div>
          <div v-if="activeTab === 'new-course'" class="form-grid">
            <div class="form-group" :class="{ 'field-error': krsCourse.errors.code }">
              <label>Kode MK <span class="required">*</span></label>
              <input v-model="newCourse.code" placeholder="IF101" maxlength="10"
                     @blur="krsCourse.validateField('code')"
                     @input="krsCourse.validateField('code')" />
              <small v-if="krsCourse.errors.code" class="field-msg">{{ krsCourse.errors.code }}</small>
            </div>
            <div class="form-group" :class="{ 'field-error': krsCourse.errors.credits }">
              <label>SKS <span class="required">*</span></label>
              <input v-model.number="newCourse.credits" type="number" min="1" max="6"
                     @blur="krsCourse.validateField('credits')" @input="krsCourse.validateField('credits')" />
              <small v-if="krsCourse.errors.credits" class="field-msg">{{ krsCourse.errors.credits }}</small>
            </div>
            <div class="form-group" style="grid-column: span 2" :class="{ 'field-error': krsCourse.errors.name }">
              <label>Nama MK <span class="required">*</span></label>
              <input v-model="newCourse.name" placeholder="Nama mata kuliah" minlength="3" maxlength="120"
                     @blur="krsCourse.validateField('name')" @input="krsCourse.validateField('name')" />
              <small v-if="krsCourse.errors.name" class="field-msg">{{ krsCourse.errors.name }}</small>
            </div>
            <div class="form-group" style="grid-column: span 2"><button class="btn btn-primary" @click="saveNewCourse">Simpan Mata Kuliah</button><button class="btn btn-ghost" @click="activeTab = 'select'">Kembali</button></div>
          </div>
          <div v-if="editingId && activeTab === 'edit'" class="form-grid">
            <div class="form-group"><label>NIM</label><input :value="form.student_nim" disabled class="input-disabled" /></div>
            <div class="form-group"><label>Nama Mahasiswa</label><input :value="form.student_name" disabled class="input-disabled" /></div>
            <div class="form-group"><label>Kode MK</label><input :value="form.course_code" disabled class="input-disabled" /></div>
            <div class="form-group"><label>Nama MK</label><input :value="form.course_name" disabled class="input-disabled" /></div>
            <div class="form-group"><label>Status <span class="required">*</span></label><select v-model="form.status" required><option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option></select></div>
            <div class="form-group"><label>Nilai</label><select v-model="form.grade"><option value="">-</option><option v-for="g in ['A','A-','B+','B','B-','C','C-','D','E','I','S','K']" :key="g" :value="g">{{ g }}</option></select></div>
            <div class="form-group"><label>IPK</label><input v-model.number="form.gpa_points" type="number" min="0" max="4" step="0.01" /></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost" @click="showForm = false">Batal</button>
          <button v-if="!editingId && activeTab === 'select'" class="btn btn-primary" @click="save" :disabled="saving || !form.student_nim || !form.course_code || !form.academic_year || !form.semester">Simpan KRS</button>
          <button v-else-if="editingId" class="btn btn-primary" @click="save" :disabled="saving">
            <span v-if="saving" style="display:inline-flex;align-items:center;gap:6px"><svg style="animation:spin 1s linear infinite;width:14px;height:14px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>Memperbarui...</span>
            <span v-else>Update KRS</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation -->
    <div v-if="deleteConfirm" class="modal-overlay" @click.self="deleteConfirm = null">
      <div class="modal-card modal-sm">
        <div class="modal-header"><h3>Konfirmasi Hapus</h3><button class="btn-close" @click="deleteConfirm = null">&times;</button></div>
        <div class="modal-body"><p>Apakah Anda yakin ingin menghapus KRS ini? Tindakan ini tidak dapat dibatalkan.</p><p class="text-muted" style="margin-top:10px;font-size:12px">Catatan: Data mahasiswa dan mata kuliah tetap tersimpan.</p></div>
        <div class="modal-footer"><button class="btn btn-ghost" @click="deleteConfirm = null">Batal</button><button class="btn btn-danger" @click="remove">Hapus</button></div>
      </div>
    </div>

    <!-- Advanced Filter Panel -->
    <div v-if="showFilterPanel" class="modal-overlay" @click.self="showFilterPanel = false">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h3>Filter Multi-Kolom</h3>
          <button class="btn-close" @click="showFilterPanel = false">&times;</button>
        </div>
        <div class="modal-body">
          <!-- Logic Toggle -->
          <div class="filter-logic-toggle">
            <span class="logic-label">Cocokkan:</span>
            <label class="logic-radio">
              <input type="radio" v-model="filterLogic" value="and" />
              <span>Semua Kondisi (AND)</span>
            </label>
            <label class="logic-radio">
              <input type="radio" v-model="filterLogic" value="or" />
              <span>Salah Satu (OR)</span>
            </label>
          </div>

          <!-- Filter Rows -->
          <div v-if="advancedFilters.length" class="filter-rows">
            <div v-for="(f, i) in advancedFilters" :key="i" class="filter-row">
              <div class="filter-row-index">{{ i + 1 }}</div>
              <select v-model="f.column" class="filter-col-select" @change="onRowColumnChange(i)">
                <option v-for="c in filterColumns" :key="c.key" :value="c.key">{{ getFilterColumnLabel(c.key) }}</option>
              </select>
              <select v-model="f.operator" class="filter-op-select" @change="onRowOperatorChange(i)">
                <option v-for="op in getRowOperators(f)" :key="op" :value="op">{{ operatorLabels[op] }}</option>
              </select>
              <div class="filter-val-input" v-if="f.operator === 'between'">
                <input v-model="f.min" type="text" placeholder="Nilai awal..." class="filter-input filter-range-input" />
                <span class="range-separator">—</span>
                <input v-model="f.max" type="text" placeholder="Nilai akhir..." class="filter-input filter-range-input" />
              </div>
              <div v-else-if="f.operator === 'in'" class="filter-multi-input">
                <select v-model="f.value" multiple class="filter-input filter-multi-select">
                  <option v-for="opt in getEnumOptions(f.column)" :key="opt" :value="opt">{{ opt }}</option>
                </select>
              </div>
              <input v-else-if="f.operator !== 'is_null' && f.operator !== 'is_not_null'" v-model="f.value" :placeholder="getPlaceholder(f.column, f.operator)" class="filter-input" />
              <span v-else class="filter-null-label">{{ f.operator === 'is_null' ? '(kosong)' : '(tidak kosong)' }}</span>
              <button class="btn-icon btn-delete-filter" @click="removeFilter(i)" title="Hapus filter">×</button>
            </div>
          </div>

          <!-- Add New Filter Row -->
          <div class="add-filter-row">
            <select v-model="newFilter.column" @change="onColumnChange">
              <option value="" disabled>Pilih kolom...</option>
              <option v-for="c in filterColumns" :key="c.key" :value="c.key">{{ getFilterColumnLabel(c.key) }}</option>
            </select>
            <select v-model="newFilter.operator">
              <option v-for="op in currentOperators" :key="op" :value="op">{{ operatorLabels[op] }}</option>
            </select>
            <div v-if="newFilter.operator === 'between'" class="add-range-inputs">
              <input v-model="newFilterMin" type="text" placeholder="Dari..." class="filter-input filter-range-input" />
              <span class="range-separator">—</span>
              <input v-model="newFilterMax" type="text" placeholder="Sampai..." class="filter-input filter-range-input" />
            </div>
            <div v-else-if="newFilter.operator === 'in'" class="add-multi-input">
              <select v-model="newFilterMultiValues" multiple class="filter-input filter-multi-select">
                <option v-for="opt in getEnumOptions(newFilter.column)" :key="opt" :value="opt">{{ opt }}</option>
              </select>
            </div>
            <input v-else-if="newFilter.operator !== 'is_null' && newFilter.operator !== 'is_not_null'" v-model="newFilter.value" :placeholder="getPlaceholder(newFilter.column, newFilter.operator)" class="filter-input" />
            <span v-else class="filter-null-label">-</span>
            <button class="btn btn-primary btn-sm" @click="addFilter" :disabled="!newFilter.column">Tambah</button>
          </div>

          <p v-if="!filterColumns.length" class="text-muted" style="font-size:12px;margin-top:8px;text-align:center">Memuat kolom filter...</p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost" @click="showFilterPanel = false">Tutup</button>
          <button class="btn btn-ghost btn-danger" @click="resetAllFilters">Reset / Hapus Semua</button>
          <button class="btn btn-primary" @click="applyFilters">Terapkan</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.quick-filters {
  display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
  padding: 12px 16px;
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(24px) saturate(200%);
  -webkit-backdrop-filter: blur(24px) saturate(200%);
  border-radius: var(--radius);
  border: 1px solid rgba(255, 255, 255, 0.18);
  flex-wrap: wrap;
  box-shadow: var(--shadow-md), inset 0 1px 0 rgba(255,255,255,0.6);
}
.quick-label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-right: 4px; }
.quick-btn {
  padding: 6px 14px; border-radius: var(--radius-full);
  border: 1px solid var(--border);
  background: var(--bg-secondary);
  cursor: pointer; font-size: 13px; font-weight: 500;
  transition: all 0.2s; color: var(--text-secondary);
}
.quick-btn:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }
.quick-btn.active { font-weight: 600; color: white; border-color: var(--accent); background: var(--accent); box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3); }
.quick-separator { width: 1px; height: 24px; background: var(--border); margin: 0 4px; }

/* Filter Chips */
.filter-chips { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.chip-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.filter-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px;
  background: rgba(0, 122, 255, 0.12);
  border: 1px solid rgba(0, 122, 255, 0.25);
  border-radius: var(--radius-full);
  font-size: 12px; color: #1e40af; font-weight: 500;
  backdrop-filter: blur(8px);
}
.sort-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px;
  background: rgba(52, 199, 89, 0.12);
  border: 1px solid rgba(52, 199, 89, 0.25);
  border-radius: var(--radius-full);
  font-size: 12px; color: #166534; font-weight: 500;
  backdrop-filter: blur(8px);
}
.chip-remove { background: none; border: none; cursor: pointer; color: inherit; font-size: 16px; line-height: 1; padding: 0 2px; opacity: 0.6; }
.chip-remove:hover { opacity: 1; }

/* Sort Bar */
.sort-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: var(--success-light);
  border: 1px solid rgba(16, 185, 129, 0.3);
  border-radius: var(--radius);
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.sort-bar-label { font-size: 12px; font-weight: 600; color: var(--success); text-transform: uppercase; letter-spacing: 0.5px; }
.sort-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 12px;
  color: var(--text-secondary);
  font-weight: 500;
}
.sort-tag-btn { background: none; border: none; cursor: pointer; font-size: 12px; color: var(--success); padding: 0 2px; line-height: 1; }
.sort-tag-btn:hover { opacity: 0.7; }
.sort-tag-remove { background: none; border: none; cursor: pointer; font-size: 14px; color: var(--text-muted); padding: 0 2px; opacity: 0.5; line-height: 1; }
.sort-tag-remove:hover { opacity: 1; color: var(--danger); }
.btn-xs { padding: 4px 10px; font-size: 12px; }

.export-progress-bar {
  display: flex; align-items: center; gap: 12px;
  background: rgba(0, 122, 255, 0.08);
  backdrop-filter: blur(16px) saturate(180%);
  border: 1px solid rgba(0, 122, 255, 0.2);
  border-radius: var(--radius); padding: 10px 16px;
  margin-bottom: 12px; flex-wrap: wrap;
  box-shadow: var(--shadow-sm), inset 0 1px 0 rgba(255,255,255,0.3);
}
.export-progress-info { display: flex; gap: 12px; font-size: 13px; color: var(--text-secondary); }
.export-status { font-weight: 600; color: var(--accent); }
.export-rows { color: #94a3b8; font-size: 12px; }
.export-progress-track { flex: 1; height: 8px; background: rgba(255,255,255,0.3); border-radius: 4px; overflow: hidden; min-width: 120px; }
.export-progress-fill { height: 100%; background: linear-gradient(90deg, #007AFF, #AF52DE); transition: width 0.3s; border-radius: 4px; }
.export-pct { font-size: 13px; font-weight: 600; color: var(--accent); min-width: 40px; text-align: right; }

.export-success-toast {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; margin-bottom: 12px;
  background: var(--success-light);
  border: 1px solid var(--success);
  border-radius: var(--radius);
  font-size: 14px; font-weight: 500; color: var(--success);
  animation: slideIn 0.3s ease-out;
}

/* Filter Logic Toggle */
.filter-logic-toggle {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px;
  background: rgba(255, 255, 255, 0.3);
  backdrop-filter: blur(16px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.4);
  border-radius: var(--radius); margin-bottom: 16px;
  box-shadow: var(--shadow-sm), inset 0 1px 0 rgba(255,255,255,0.4);
}
.logic-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
.logic-radio { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: var(--text-primary); padding: 4px 10px; border-radius: 8px; transition: all 0.2s; border: 1px solid transparent; }
.logic-radio:hover { background: rgba(255, 255, 255, 0.4); border-color: rgba(255, 255, 255, 0.3); }
.logic-radio input[type="radio"] { cursor: pointer; }
.logic-radio input[type="radio"]:checked + span { color: #007AFF; font-weight: 600; }

/* Filter Rows */
.filter-rows { max-height: 320px; overflow-y: auto; margin-bottom: 12px; }
.filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  background: var(--bg-tertiary);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  margin-bottom: 8px;
}
.filter-row-index { font-size: 11px; font-weight: 700; color: var(--text-muted); min-width: 20px; text-align: center; }
.filter-col-select, .filter-op-select {
  min-width: 130px;
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 13px;
  background: var(--bg-secondary);
  color: var(--text-primary);
  cursor: pointer;
}
.filter-val-input { display: flex; align-items: center; gap: 6px; flex: 1; }
.filter-range-input {
  flex: 1;
  min-width: 80px;
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 13px;
  background: var(--bg-secondary);
  color: var(--text-primary);
}
.filter-range-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
.range-separator { font-size: 13px; color: var(--text-muted); font-weight: 600; }
.filter-null-label { font-size: 13px; color: var(--text-muted); font-style: italic; }
.filter-multi-select {
  min-width: 140px;
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 13px;
  background: var(--bg-secondary);
  color: var(--text-primary);
  min-height: 36px;
}
.btn-icon.btn-delete-filter { background: none; border: none; cursor: pointer; font-size: 18px; color: var(--danger); padding: 2px 6px; opacity: 0.6; line-height: 1; transition: opacity 0.2s; }
.btn-icon.btn-delete-filter:hover { opacity: 1; background: var(--danger-light); border-radius: 4px; }

/* Add Filter Row */
.add-filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 12px;
  background: var(--success-light);
  border: 1px solid rgba(16, 185, 129, 0.3);
  border-radius: var(--radius);
}
.add-filter-row select, .add-filter-row input {
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 13px;
  background: var(--bg-secondary);
  color: var(--text-primary);
  outline: none;
}
.add-filter-row select:focus, .add-filter-row input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
.add-filter-row select { min-width: 130px; flex: 1; }
.add-filter-row input { flex: 1; min-width: 100px; }
.btn-danger {
  background: linear-gradient(135deg, #ef4444, #dc2626);
  color: white;
  border: 1px solid transparent;
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}
.btn-danger:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

.krs-tabs {
  display: flex; gap: 8px; margin-bottom: 20px;
  border-bottom: 2px solid rgba(255, 255, 255, 0.3);
  padding-bottom: 0;
}
.tab-btn {
  padding: 10px 20px; background: none; border: none;
  border-bottom: 2px solid transparent; margin-bottom: -2px;
  cursor: pointer; font-size: 14px; font-weight: 500;
  color: var(--text-muted); transition: all 0.2s;
}
.tab-btn:hover { color: var(--accent); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
.search-wrapper { position: relative; display: flex; gap: 8px; align-items: center; }
.search-wrapper input { flex: 1; }
.searching { font-size: 12px; color: var(--text-muted); padding: 4px 0; }
.search-results {
  position: absolute; top: 100%; left: 0; right: 0;
  background: rgba(255, 255, 255, 0.6);
  backdrop-filter: blur(24px) saturate(180%);
  -webkit-backdrop-filter: blur(24px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.5);
  border-radius: 10px;
  max-height: 200px; overflow-y: auto; z-index: 100;
  list-style: none; margin: 4px 0; padding: 0;
  box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255,255,255,0.5);
}
.search-results li { padding: 10px 12px; cursor: pointer; display: flex; gap: 8px; align-items: center; transition: background 0.2s; }
.search-results li:hover { background: rgba(255, 255, 255, 0.3); }
.input-disabled { background: rgba(255, 255, 255, 0.25); cursor: not-allowed; }
.load-more-wrapper { text-align: center; padding: 20px; }
.load-more-wrapper .btn { min-width: 200px; }
.btn-link { background: none; border: none; color: var(--accent); cursor: pointer; font-size: 13px; padding: 0; }
.btn-link:hover { text-decoration: underline; }

/* ─── Responsive: Desktop → Tablet → Mobile ─── */
.view { width: 100%; min-width: 0; }

/* Layout padding for all screens */
.view { padding: 20px; }
@media (max-width: 768px) { .view { padding: 12px; } }
@media (max-width: 480px) { .view { padding: 8px; } }

/* Stats: 4-col → 2-col → 1-col */
.stats-row { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 1024px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px) { .stats-row { grid-template-columns: 1fr; } .stat-card { padding: 14px; gap: 12px; } .stat-value { font-size: 22px; } .stat-icon { width: 40px; height: 40px; } }

/* Quick filters: hide label on small screens */
@media (max-width: 640px) {
  .quick-label { display: none; }
  .quick-filters { padding: 10px 12px; gap: 6px; }
  .quick-btn { padding: 5px 10px; font-size: 12px; }
  .quick-separator { height: 18px; }
}

/* Toolbar: collapse on mobile */
@media (max-width: 640px) {
  .toolbar { flex-direction: column; align-items: stretch; gap: 10px; }
  .toolbar-left, .toolbar-right { justify-content: center; }
  .search-box { flex-wrap: wrap; }
  .search-box input { width: 100px !important; font-size: 13px; }
}

/* Search boxes: fixed widths only on desktop */
@media (min-width: 641px) {
  .search-box input:nth-of-type(1) { width: 130px; }
  .search-box input:nth-of-type(2) { width: 160px; }
  .search-box input:nth-of-type(3) { width: 120px; }
}

/* Table: horizontal scroll wrapper on mobile */
.table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.data-table { min-width: 900px; }
@media (max-width: 900px) {
  .data-table th, .data-table td { padding: 10px 12px; font-size: 13px; white-space: nowrap; }
  .course-subtitle { max-width: 120px; }
}

/* Filter panel modal: full-width on mobile */
.modal-lg { width: 800px; }
@media (max-width: 860px) { .modal-lg { width: 95vw; } }
@media (max-width: 640px) {
  .modal-lg { width: 100vw; max-width: 100vw; border-radius: var(--radius-lg) var(--radius-lg) 0 0; margin-top: auto; }
  .modal-overlay { align-items: flex-end; padding: 0; }
}

/* Sort panel */
.modal-sm { width: 400px; }
@media (max-width: 480px) { .modal-sm { width: 95vw; } }

/* Pagination: stack on mobile */
@media (max-width: 500px) {
  .pagination-bar { flex-direction: column; gap: 8px; }
  .pagination-info { justify-content: center; }
  .pagination-controls { justify-content: center; }
}

/* Export progress bar */
@media (max-width: 500px) {
  .export-progress-bar { flex-wrap: wrap; padding: 8px 12px; }
  .export-progress-track { flex-basis: 100%; order: 3; }
}

/* Filter chips / sort bar */
@media (max-width: 640px) {
  .filter-chips, .sort-bar { font-size: 12px; }
  .filter-chip, .sort-tag { padding: 3px 8px; font-size: 11px; }
}

/* KRS modal form tabs: scrollable on very narrow screens */
@media (max-width: 480px) {
  .krs-tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; gap: 0; }
  .tab-btn { white-space: nowrap; padding: 10px 14px; font-size: 13px; }
  .modal-body { padding: 16px; }
  .modal-header { padding: 16px; }
  .modal-footer { padding: 12px 16px; flex-direction: column; }
  .modal-footer .btn { width: 100%; justify-content: center; }
}

/* Global Toast Notification */
.global-toast {
  position: fixed;
  top: 24px;
  right: 24px;
  padding: 12px 24px;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 600;
  z-index: 9999;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
  pointer-events: none;
  white-space: nowrap;
  animation: toastIn 0.3s cubic-bezier(0.4, 0, 0.2, 1), toastOut 0.3s cubic-bezier(0.4, 0, 0.2, 1) 3.5s forwards;
}
.toast-success { background: #10b981; color: white; }
.toast-error { background: #ef4444; color: white; }
@keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
@keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(20px); } }
</style>
