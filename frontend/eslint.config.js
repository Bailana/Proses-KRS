import js from '@eslint/js';
import vue from 'eslint-plugin-vue';

export default [
  js.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    rules: {
      'vue/multi-word-component-names': 'off',
    },
  },
  {
    ignores: ['node_modules/', 'dist/'],
  },
];
