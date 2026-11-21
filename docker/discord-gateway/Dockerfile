FROM node:22-alpine

# pi is the optional LLM layer of the Discord agent. It is installed globally
# (same package as clients/agent) so `pi --print` is on PATH; when it is absent,
# missing, or times out, agent.mjs falls back to the deterministic MCP client.
ARG PI_VERSION=1.6.0
RUN npm install -g @earendil-works/pi-coding-agent@${PI_VERSION} --no-audit --no-fund

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund
COPY bot.mjs agent.mjs ./

# pi writes ~/.pi/agent/mcp.json; keep HOME writable for the unprivileged user.
ENV HOME=/home/node
RUN mkdir -p /home/node/.pi/agent && chown -R node:node /home/node
USER node

CMD ["node", "bot.mjs"]
