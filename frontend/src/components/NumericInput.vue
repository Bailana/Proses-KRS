<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  maxLength: { type: Number, default: 12 },
  placeholder: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'blur'])

const inputRef = ref(null)

const displayValue = computed(() => {
  return (props.modelValue || '').replace(/[^0-9]/g, '').slice(0, props.maxLength)
})

function handleBeforeinput(event) {
  if (event.inputType === 'insertText' || event.inputType === 'insertParagraph') {
    const data = event.data || ''
    if (!/[\d]/.test(data)) {
      event.preventDefault()
    }
  }
}

function handleInput(event) {
  const raw = event.target.value
  const filtered = raw.replace(/[^0-9]/g, '').slice(0, props.maxLength)
  emit('update:modelValue', filtered)
}

function handlePaste(event) {
  event.preventDefault()
  const clipboardData = event.clipboardData || window.clipboardData
  const pasted = (clipboardData.getData('text/plain') || '').replace(/[^0-9]/g, '').slice(0, props.maxLength)
  emit('update:modelValue', pasted)
}

function handleBlur() {
  emit('blur', displayValue.value)
}
</script>

<template>
  <input
    ref="inputRef"
    :value="displayValue"
    :placeholder="placeholder"
    :maxlength="maxLength"
    inputmode="numeric"
    pattern="[0-9]*"
    @beforeinput="handleBeforeinput"
    @input="handleInput"
    @paste="handlePaste"
    @blur="handleBlur"
    class="w-full rounded-[10px] border border-[rgba(255,255,255,0.4)] bg-[rgba(255,255,255,0.35)] px-3 py-2 text-sm outline-none backdrop-blur-[10px] transition-all focus:border-[rgba(0,122,255,0.6)] focus:ring-2 focus:ring-[rgba(0,122,255,0.2)]"
  />
</template>
