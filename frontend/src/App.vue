<script setup>
import { ref, computed, onMounted } from 'vue'
import KrsView from './components/KrsView.vue'

const activeTab = ref('krs')
const sidebarOpen = ref(true)
const mobileMenuOpen = ref(false)

const tabs = [
  { key: 'krs', label: 'KRS', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
    color: '#f59e0b', bg: '#fef3c7' },
]

const currentView = computed(() => KrsView)

const activeTabData = computed(() => tabs.find(t => t.key === activeTab.value))

onMounted(() => {
  const saved = localStorage.getItem('sidebar-collapsed')
  if (saved === 'true') sidebarOpen.value = false
})

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
  localStorage.setItem('sidebar-collapsed', sidebarOpen.value)
}
</script>

<template>
  <div class="layout">
    <!-- Mobile overlay -->
    <div v-if="mobileMenuOpen" class="mobile-overlay" @click="mobileMenuOpen = false" />

    <!-- Sidebar -->
    <aside :class="['sidebar', { collapsed: !sidebarOpen, mobile: mobileMenuOpen }]">
      <div class="sidebar-header">
        <div class="logo">
          <div class="logo-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
          </div>
          <span class="logo-text" :class="{ hidden: !sidebarOpen }">Proses Akademik</span>
        </div>
        <button class="sidebar-close" @click="mobileMenuOpen = false">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-section">
          <span class="nav-section-label" :class="{ hidden: !sidebarOpen }">Menu</span>
          <button
            v-for="tab in tabs"
            :key="tab.key"
            :class="['nav-item', { active: activeTab === tab.key }]"
            :style="activeTab === tab.key ? { '--tab-color': tab.color, '--tab-bg': tab.bg } : {}"
            @click="activeTab = tab.key; mobileMenuOpen = false"
          >
            <span class="nav-icon" :style="{ color: tab.color, background: tab.bg }" v-html="tab.icon"></span>
            <span class="nav-label" :class="{ hidden: !sidebarOpen }">{{ tab.label }}</span>
            <span v-if="activeTab === tab.key" class="nav-indicator" :style="{ background: tab.color }" />
          </button>
        </div>
      </nav>

      <div class="sidebar-footer" :class="{ hidden: !sidebarOpen }">
        <div class="sidebar-info">
          <div class="info-dot" style="background: var(--success)"></div>
          <span>System Online</span>
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
      <!-- Top Bar -->
      <header class="topbar">
        <div class="topbar-left">
          <button class="menu-toggle" @click="mobileMenuOpen = true">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </button>
          <button class="sidebar-toggle" @click="toggleSidebar" title="Toggle sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
          </button>
          <div class="page-title">
            <h1>{{ activeTabData?.label || 'Dashboard' }}</h1>
            <p>Manage your academic data efficiently</p>
          </div>
        </div>
        <div class="topbar-right">
          <div class="user-avatar">
            <span>AD</span>
          </div>
        </div>
      </header>

      <!-- Content -->
      <main class="content">
        <transition name="fade" mode="out-in">
          <component :is="currentView" :key="activeTab" />
        </transition>
      </main>
    </div>
  </div>
</template>
