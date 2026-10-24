import { createRoot } from 'react-dom/client';
import { MetaTemplateCreator } from './meta-template-creator/MetaTemplateCreator';

const container = document.getElementById('meta-template-creator-root');

if (container) {
    createRoot(container).render(<MetaTemplateCreator />);
}
