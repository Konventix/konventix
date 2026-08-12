import { cva } from "class-variance-authority"

export { default as NavigationMenu } from "./NavigationMenu.vue"
export { default as NavigationMenuContent } from "./NavigationMenuContent.vue"
export { default as NavigationMenuIndicator } from "./NavigationMenuIndicator.vue"
export { default as NavigationMenuItem } from "./NavigationMenuItem.vue"
export { default as NavigationMenuLink } from "./NavigationMenuLink.vue"
export { default as NavigationMenuList } from "./NavigationMenuList.vue"
export { default as NavigationMenuTrigger } from "./NavigationMenuTrigger.vue"
export { default as NavigationMenuViewport } from "./NavigationMenuViewport.vue"

export const navigationMenuTriggerStyle = cva(
  "group inline-flex h-9 w-max items-center justify-center rounded-md bg-background px-4 py-2 text-sm font-medium hover:bg-subtle hover:text-subtle-foreground focus:bg-subtle focus:text-subtle-foreground disabled:pointer-events-none disabled:opacity-50 data-[state=open]:hover:bg-subtle data-[state=open]:text-subtle-foreground data-[state=open]:focus:bg-subtle data-[state=open]:bg-subtle/50 focus-visible:ring-ring/50 outline-none transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:outline-1",
)
