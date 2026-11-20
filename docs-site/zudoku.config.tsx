import type { ZudokuConfig } from "zudoku";
import "./styles.css";

const moduleDocs = {
  "event-people": [
    "events",
    "registration",
    "seating",
    "teams",
    "friends",
    "identity",
    "notifications",
    "schedule",
    "catering",
    "lfg",
    "voting",
    "presence",
    "recap",
    "news",
  ],
  competition: ["tournaments", "games", "gameservers", "stats", "casting"],
  "site-ops": [
    "gamerecipes",
    "hosts",
    "customservers",
    "lancache",
    "provisioning",
    "preflight",
    "roomaudio",
  ],
  media: ["gallery", "files", "jukebox", "infoscreen"],
  integrations: ["discord", "voice", "webvoice", "mcp"],
} as const;

const moduleCategory = (label: string, group: keyof typeof moduleDocs, icon: string) => ({
  type: "category" as const,
  label,
  icon,
  items: [
    { type: "doc" as const, file: `system/${group}`, label: "Group overview" },
    ...moduleDocs[group].map((slug) => ({
      type: "doc" as const,
      file: `system/modules/${slug}`,
    })),
  ],
});

const config: ZudokuConfig = {
  basePath: "/docs",
  site: {
    title: "LANoMAT Docs",
    logo: {
      src: {
        light: "/logo-light.svg",
        dark: "/logo-dark.svg",
      },
      alt: "LANoMAT Docs",
      width: 104,
      height: 28,
      href: "/start/introduction",
    },
  },
  metadata: {
    title: "LANoMAT Docs",
    description:
      "Documentation for LANoMAT: the LAN party control desk. Modules, game recipes, the MCP server, the pi agent and the REST API.",
    favicon: "/favicon.svg",
    applicationName: "LANoMAT",
    keywords: ["LAN party", "LANoMAT", "tournaments", "seating", "MCP", "game recipes"],
  },
  theme: {
    light: {
      background: "#F7F8FA",
      foreground: "#14181C",
      card: "#FFFFFF",
      cardForeground: "#14181C",
      popover: "#FFFFFF",
      popoverForeground: "#14181C",
      primary: "#A85A00",
      primaryForeground: "#FFFFFF",
      secondary: "#EAEEF2",
      secondaryForeground: "#14181C",
      muted: "#EEF1F4",
      mutedForeground: "#5B646E",
      accent: "#EEF1F4",
      accentForeground: "#14181C",
      destructive: "#DC2626",
      destructiveForeground: "#FFFFFF",
      border: "#E1E5EA",
      input: "#DCE1E7",
      ring: "#A85A00",
      radius: "0.375rem",
    },
    dark: {
      background: "#0F1215",
      foreground: "#E7ECEF",
      card: "#171B20",
      cardForeground: "#E7ECEF",
      popover: "#171B20",
      popoverForeground: "#E7ECEF",
      primary: "#FFB020",
      primaryForeground: "#17130A",
      secondary: "#1E242B",
      secondaryForeground: "#E7ECEF",
      muted: "#1E242B",
      mutedForeground: "#8A949E",
      accent: "#262C33",
      accentForeground: "#E7ECEF",
      destructive: "#E5484D",
      destructiveForeground: "#FFFFFF",
      border: "#262C33",
      input: "#2B323A",
      ring: "#FFB020",
      radius: "0.375rem",
    },
    fonts: {
      sans: "Space Grotesk",
      mono: "JetBrains Mono",
    },
  },
  header: {
    placements: {
      navigation: "start",
      search: "end",
      auth: "end",
    },
  },
  navigation: [
    {
      type: "category",
      label: "Start",
      icon: "rocket",
      items: [
        { type: "doc", file: "start/introduction", label: "Introduction" },
        { type: "doc", file: "start/quickstart", label: "Quickstart" },
        { type: "doc", file: "start/deployment", label: "Deployment" },
        { type: "doc", file: "start/tour", label: "Screen tour" },
      ],
    },
    {
      type: "category",
      label: "The system",
      icon: "layout-dashboard",
      items: [
        { type: "doc", file: "system/overview", label: "Overview" },
        { type: "doc", file: "system/games-library", label: "Games library" },
        moduleCategory("Event & people", "event-people", "users"),
        moduleCategory("Competition", "competition", "trophy"),
        moduleCategory("Site & ops", "site-ops", "server"),
        moduleCategory("Media", "media", "image"),
        moduleCategory("Integrations", "integrations", "plug"),
      ],
    },
    {
      type: "category",
      label: "Game recipes",
      icon: "package",
      items: [
        { type: "doc", file: "recipes/overview", label: "How recipes work" },
        { type: "doc", file: "recipes/ut2004", label: "UT2004 + UT2004VR" },
        { type: "doc", file: "recipes/ridge-racer", label: "Ridge Racer" },
        { type: "doc", file: "recipes/contributing", label: "Contributing" },
        { type: "doc", file: "recipes/smb", label: "SMB import" },
      ],
    },
    {
      type: "category",
      label: "MCP",
      icon: "bot",
      items: [
        { type: "doc", file: "mcp/overview", label: "Server & auth" },
        { type: "doc", file: "mcp/tools", label: "Tools & resources" },
        { type: "doc", file: "mcp/vibecoding", label: "Vibecoding recipes" },
        { type: "doc", file: "mcp/security", label: "Security" },
      ],
    },
    {
      type: "category",
      label: "pi agent",
      icon: "terminal",
      items: [
        { type: "doc", file: "agent/overview", label: "Overview" },
        { type: "doc", file: "agent/install", label: "Install & login" },
        { type: "doc", file: "agent/venice", label: "Models & fuel" },
        { type: "doc", file: "agent/troubleshooting", label: "Troubleshooting" },
      ],
    },
    {
      type: "link",
      label: "API reference",
      to: "/api",
      icon: "code",
    },
    {
      type: "category",
      label: "Reference",
      icon: "book-open",
      items: [
        { type: "doc", file: "reference/commands", label: "Commands" },
        { type: "doc", file: "reference/env", label: "Environment" },
        { type: "doc", file: "reference/troubleshooting", label: "Troubleshooting" },
        { type: "doc", file: "reference/changelog", label: "Changelog" },
      ],
    },
  ],
  redirects: [{ from: "/", to: "/start/introduction" }],
  apis: {
    type: "file",
    input: "./apis/lanomat.openapi.yaml",
    path: "/api",
    options: {
      examplesLanguage: "shell",
      showInfoPage: true,
      disableSecurity: false,
      schemaDownload: { enabled: true, fileName: "lanomat-openapi" },
    },
  },
  docs: {
    files: "/pages/**/*.{md,mdx}",
    defaultOptions: {
      showLastModified: false,
      suggestEdit: {
        url: "https://github.com/raute1-org/LANoMAT/edit/main/docs-site/pages",
        text: "Edit this page",
      },
    },
    llms: {
      llmsTxt: true,
      llmsTxtFull: true,
      title: "LANoMAT Docs",
      description:
        "The LAN party control desk: modules, game recipes, MCP server, pi agent and REST API.",
      instructions:
        "Use these docs when installing, operating or integrating LANoMAT. Recipes are agent skills: read the recipe skill before planning an install.",
    },
  },
  footer: {
    columns: [
      {
        title: "Product",
        links: [
          { label: "Introduction", href: "/docs/start/introduction" },
          { label: "Modules", href: "/docs/system/overview" },
          { label: "Game recipes", href: "/docs/recipes/overview" },
        ],
      },
      {
        title: "For agents",
        links: [
          { label: "MCP server", href: "/docs/mcp/overview" },
          { label: "Tools & resources", href: "/docs/mcp/tools" },
          { label: "API reference", href: "/docs/api" },
        ],
      },
      {
        title: "Operate",
        links: [
          { label: "Deployment", href: "/docs/start/deployment" },
          { label: "Commands", href: "/docs/reference/commands" },
          { label: "Changelog", href: "/docs/reference/changelog" },
        ],
      },
    ],
    copyright: "LANoMAT · the quiet control desk, the loud beamer",
  },
};

export default config;
