import {
  SurfaceRegistry,
  UiCommandExecutor,
  type UiCommand,
} from "../resources/js/client.js";

let highlighted = false;
const surfaces = new SurfaceRegistry();

surfaces.register({
  id: "lesson.show",
  snapshot: () => ({
    title: "Photosynthesis",
    components: {
      "lesson.topic_1": {
        type: "goal",
        label: "Understand photosynthesis",
        state: { highlighted },
        actions: ["highlight"],
      },
    },
  }),
  actions: {
    highlight: () => {
      highlighted = true;
    },
  },
});

const command: UiCommand = {
  id: "cmd_demo",
  session_id: "demo_session",
  call_id: "call_demo",
  base_revision: 1,
  surface: "lesson.show",
  action: "highlight",
  target: "lesson.topic_1",
  arguments: {},
  expires_at: new Date(Date.now() + 15_000).toISOString(),
  nonce: crypto.randomUUID(),
};

const result = await new UiCommandExecutor("demo_session", surfaces).execute(command);

console.log("Browser bridge result:", JSON.stringify(result, null, 2));
