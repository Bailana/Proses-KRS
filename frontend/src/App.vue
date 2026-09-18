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

<style scoped>
.layout {
  display: flex;
  min-height: 100vh;
  background: var(--bg-primary);
}

/* Sidebar */
.sidebar {
  width: 260px;
  min-height: 100vh;
  background: var(--bg-secondary);
  position: fixed;
  left: 0;
  top: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
  transition: var(--transition);
  border-right: 1px solid var(--border);
  box-shadow: 4px 0 24px rgba(0, 0, 0, 0.08);
}
.sidebar.collapsed { width: 72px; }
.sidebar.mobile { transform: translateX(-100%); }
.sidebar.mobile.open { transform: translateX(0); }

.sidebar-header {
  padding: 20px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--border);
}
.logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
.logo-icon {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}
.logo-text {
  color: var(--text-primary); font-size: 15px; font-weight: 700;
  white-space: nowrap;
  letter-spacing: -0.3px;
}
.sidebar-close {
  display: none; background: none; border: none; color: var(--text-muted); cursor: pointer;
  padding: 4px; border-radius: 6px;
}
.sidebar-close:hover { background: var(--bg-tertiary); }

.sidebar-nav { flex: 1; padding: 12px 8px; overflow-y: auto; }
.nav-section { margin-bottom: 8px; }
.nav-section-label {
  display: block; color: var(--text-muted);
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.8px; padding: 8px 12px 4px;
}
.nav-item {
  display: flex; align-items: center; gap: 12px;
  width: 100%; padding: 10px 12px;
  border: none; background: transparent;
  border-radius: var(--radius); cursor: pointer;
  color: var(--text-muted);
  font-size: 14px; font-weight: 500;
  text-align: left; transition: var(--transition);
  position: relative;
}
.nav-item:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}
.nav-item.active {
  background: var(--accent-light);
  color: var(--accent);
  font-weight: 600;
}
.nav-icon {
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 8px; flex-shrink: 0;
  transition: var(--transition);
}
.nav-label { white-space: nowrap; }
.nav-indicator {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  width: 4px; height: 20px; border-radius: 2px;
}
.sidebar-footer {
  padding: 16px;
  border-top: 1px solid var(--border);
}
.sidebar-info {
  display: flex; align-items: center; gap: 8px;
  color: var(--text-muted); font-size: 12px;
}
.info-dot { width: 8px; height: 8px; border-radius: 50%; animation: pulse 2s infinite; }

/* Main Wrapper */
.main-wrapper {
  flex: 1;
  margin-left: 260px;
  display: flex;
  flex-direction: column;
  transition: var(--transition);
  position: relative;
  z-index: 1;
}
.sidebar.collapsed ~ .main-wrapper,
.main-wrapper.collapsed { margin-left: 72px; }

/* Topbar */
.topbar {
  height: 64px;
  background: var(--bg-secondary);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  position: sticky;
  top: 0;
  z-index: 50;
  box-shadow: var(--shadow);
}
.topbar-left { display: flex; align-items: center; gap: 16px; }
.menu-toggle {
  display: none; background: none; border: none; cursor: pointer;
  color: var(--text-secondary); padding: 6px; border-radius: 8px;
}
.menu-toggle:hover { background: var(--bg-tertiary); }
.sidebar-toggle {
  background: none; border: 1px solid var(--border); cursor: pointer;
  color: var(--text-muted); padding: 6px 8px; border-radius: 8px;
  transition: var(--transition);
}
.sidebar-toggle:hover { color: var(--text-primary); border-color: var(--border-light); }
.page-title h1 { font-size: 18px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.3px; }
.page-title p { font-size: 12px; color: var(--text-muted); }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.user-avatar {
  width: 36px; height: 36px; border-radius: 10px;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 13px; font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

/* Content */
.content {
  flex: 1;
  padding: 24px;
  overflow-y: auto;
  background: transparent;
}

/* Transition */
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(4px); }

.hidden { display: none !important; }

/* Mobile */
@media (max-width: 1024px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.mobile { transform: translateX(0); }
  .main-wrapper { margin-left: 0 !important; }
  .menu-toggle { display: flex; }
  .mobile-overlay {
    position: fixed; inset: 0;
    background: rgba(0, 0, 0, 0.3);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 90; animation: fadeIn 0.2s ease;
  }
}
@media (max-width: 640px) {
  .content { padding: 16px; }
  .topbar { padding: 0 16px; }
  .page-title h1 { font-size: 16px; }
  .page-title p { display: none; }
}
</style>
