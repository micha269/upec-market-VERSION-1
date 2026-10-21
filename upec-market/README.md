# UPEC Market React client

This directory contains the React/Vite presentation client. The Laravel API and the overall architecture, setup, and migration roadmap are documented in the [workspace README](../README.md).

The Vite development server forwards `/api` requests to `http://127.0.0.1:8000` by default. Set `VITE_API_PROXY_TARGET` in `.env.local` to use a different local API server.
