import {
  createRouter,
  createWebHistory,
  type RouteRecordRaw,
  type Router,
} from 'vue-router'
import SearchView from '@/views/SearchView.vue'
import QuoteDetailView from '@/views/QuoteDetailView.vue'
import SourceDetailView from '@/views/SourceDetailView.vue'
import AdminView from '@/views/AdminView.vue'
import NotFoundView from '@/views/NotFoundView.vue'

/** Named route identifiers used across the app. */
export const RouteName = {
  Search: 'search',
  QuoteDetail: 'quote-detail',
  SourceDetail: 'source-detail',
  Admin: 'admin',
  NotFound: 'not-found',
} as const

// History mode relies on the SPA fallback to index.html configured in
// .htaccess (design.md, Req 16.1/16.4), so deep links resolve to the app.
export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: RouteName.Search,
    component: SearchView,
  },
  {
    path: '/quotes/:id',
    name: RouteName.QuoteDetail,
    component: QuoteDetailView,
    // Pass :id as a prop so the view does not read the route directly.
    props: true,
  },
  {
    path: '/sources/:id',
    name: RouteName.SourceDetail,
    component: SourceDetailView,
    props: true,
  },
  {
    path: '/admin',
    name: RouteName.Admin,
    component: AdminView,
    // A session guard is added in a later task (Req 7).
    meta: { requiresAuth: true },
  },
  {
    path: '/:pathMatch(.*)*',
    name: RouteName.NotFound,
    component: NotFoundView,
  },
]

export function createAppRouter(): Router {
  return createRouter({
    history: createWebHistory(),
    routes,
  })
}

const router = createAppRouter()

export default router
