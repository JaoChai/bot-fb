import type { KnipConfig } from 'knip';

const config: KnipConfig = {
  entry: ['src/main.tsx', 'src/App.tsx'],
  project: ['src/**/*.{ts,tsx}'],
  ignore: [
    'src/vite-env.d.ts',
    'src/components/ui/**',  // shadcn/ui components - may appear unused but imported dynamically
  ],
  ignoreDependencies: [
    '@types/*',
    'autoprefixer',
    'tailwindcss',
  ],
};

export default config;
